#!/usr/bin/env python3
"""
openclaWP Voice Gateway — talk to any Agents API agent, full duplex.

Architecture (one sentence): Gemini Live is the voice shell (native STT/TTS,
turn-taking, barge-in); the registered agent stays the only brain, reached
through a single tool (`ask_agent`) that forwards each request to openclaWP's
agenttic chat endpoint, so memory, tool gating and transcripts stay in WP.

    browser mic (PCM 16 kHz over WS)  ⇄  this gateway  ⇄  Gemini Live API
                                            │ ask_agent(consulta)
                                            ▼
                  POST {WP_BASE}/wp-json/openclawp/v1/agenttic/{AGENT}
                  (JSON-RPC message/send, app-password auth, sticky sessionId)

The browser protocol mirrors the proven laviere voice frontend: JSON frames
{audio: <b64 pcm16k>} / {text: <str>} upstream; {audio: <b64 pcm24k>},
{type: user_transcript|agent_transcript, text, final}, {type: interrupted}
downstream.

Run:  uvicorn is embedded — `python3 gateway.py` (see README.md).
"""

import asyncio
import base64
import json
import logging
import os
import time
import uuid
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import httpx
import websockets
from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

try:
    from dotenv import load_dotenv

    load_dotenv(Path(__file__).parent / ".env")
except ImportError:
    pass

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger("voice-gateway")

# ── Config ────────────────────────────────────────────────────────────────────

GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY") or os.environ.get("GOOGLE_API_KEY", "")
GEMINI_MODEL = os.environ.get("OPENCLAWP_VOICE_MODEL", "gemini-3.1-flash-live-preview")
GEMINI_VOICE = os.environ.get("OPENCLAWP_VOICE_NAME", "Puck")
GEMINI_WS_URL = (
    "wss://generativelanguage.googleapis.com/ws/"
    "google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent"
)

WP_BASE = os.environ.get("OPENCLAWP_VOICE_WP_BASE", "").rstrip("/")
AGENT_SLUG = os.environ.get("OPENCLAWP_VOICE_AGENT", "")
AGENT_LABEL = os.environ.get("OPENCLAWP_VOICE_AGENT_LABEL", AGENT_SLUG)
AUTH_FILE = Path(
    os.environ.get("OPENCLAWP_VOICE_AUTH_FILE", str(Path.home() / ".openclawp-voice" / "auth.json"))
)
TIME_ZONE = os.environ.get("OPENCLAWP_VOICE_TZ", "America/Montevideo")
HOST = os.environ.get("OPENCLAWP_VOICE_HOST", "127.0.0.1")
PORT = int(os.environ.get("OPENCLAWP_VOICE_PORT", "8766"))

# Extra persona text appended to the system instruction (or a file with it).
PERSONA = os.environ.get("OPENCLAWP_VOICE_PERSONA", "")
PERSONA_FILE = os.environ.get("OPENCLAWP_VOICE_PERSONA_FILE", "")
if not PERSONA and PERSONA_FILE and Path(PERSONA_FILE).exists():
    PERSONA = Path(PERSONA_FILE).read_text(encoding="utf-8")


def validate_config() -> list:
    missing = []
    if not GEMINI_API_KEY:
        missing.append("GEMINI_API_KEY")
    if not WP_BASE:
        missing.append("OPENCLAWP_VOICE_WP_BASE")
    if not AGENT_SLUG:
        missing.append("OPENCLAWP_VOICE_AGENT")
    if not AUTH_FILE.exists():
        missing.append(f"auth file {AUTH_FILE} (JSON {{user, app_password}})")
    return missing


def read_auth() -> dict:
    return json.loads(AUTH_FILE.read_text(encoding="utf-8"))


# ── System instruction ────────────────────────────────────────────────────────


def build_system_instruction() -> str:
    base = f"""Sos la voz de {AGENT_LABEL}, un agente que vive en un sitio WordPress.
Tu único trabajo es ser sus oídos y su boca; el razonamiento lo hace el agente.

Reglas estrictas:
1. Para CUALQUIER consulta, dato, registro o acción que pida el usuario, llamá la
   herramienta `ask_agent` pasando el pedido completo y fiel del usuario. No
   respondas de memoria ni inventes datos: si la pregunta es sobre el dominio
   del agente, va por `ask_agent` siempre.
2. La consulta del agente puede tardar unos segundos. Apenas llames la
   herramienta avisale al usuario con una frase corta y natural ("dame un
   segundo, lo busco", "ya te digo") y esperá la respuesta.
3. Cuando llegue la respuesta, transmitila fiel y completa en voz natural. No
   agregues información que no esté en la respuesta. Si la respuesta pide una
   confirmación, pedísela al usuario y mandá su decisión de vuelta con
   `ask_agent` (ej.: "sí, confirmo").
4. Solo charla trivial (saludos, "¿me escuchás?") se responde directo, breve.
5. Hablás español rioplatense, tono cercano y conciso. Sin emojis, sin listas
   leídas como listas: convertí todo a frases habladas naturales."""
    if PERSONA:
        base += "\n\nPersona adicional:\n" + PERSONA.strip()
    return base


def temporal_context(now: datetime = None) -> str:
    """Same role as the WhatsApp bridge's buildTemporalContext(): pin relative
    dates per turn so the agent doesn't drift across a long voice session."""
    tz = ZoneInfo(TIME_ZONE)
    now = now or datetime.now(tz)
    today = now.strftime("%Y-%m-%d")
    tomorrow = (now + timedelta(days=1)).strftime("%Y-%m-%d")
    yesterday = (now - timedelta(days=1)).strftime("%Y-%m-%d")
    return (
        "[Contexto temporal del turno]\n"
        f"Hoy ({TIME_ZONE}) es {today}. Mañana es {tomorrow}. Ayer fue {yesterday}.\n"
        "Usá estas fechas para interpretar fechas relativas. No copies estas líneas en respuestas.\n"
        "\n[Mensaje del usuario (por voz)]\n"
    )


# ── ask_agent: forward a turn to the openclaWP agenttic endpoint ──────────────


async def ask_agent(consulta: str, session: dict) -> str:
    """POST the user's request to the agent and return its text reply.

    `session` carries the sticky openclawp sessionId for this voice session so
    the agent keeps conversational memory across turns.
    """
    auth = read_auth()
    req_id = f"voz-{int(time.time() * 1000)}-{uuid.uuid4().hex[:6]}"
    params = {
        "id": f"task-{req_id}",
        "message": {
            "role": "user",
            "parts": [{"type": "text", "text": temporal_context() + consulta}],
            "messageId": f"msg-{req_id}",
            "kind": "message",
        },
    }
    if session.get("sessionId"):
        params["sessionId"] = session["sessionId"]

    body = {"jsonrpc": "2.0", "id": req_id, "method": "message/send", "params": params}
    url = f"{WP_BASE}/wp-json/openclawp/v1/agenttic/{AGENT_SLUG}"

    async with httpx.AsyncClient(timeout=120.0) as client:
        res = await client.post(url, json=body, auth=(auth["user"], auth["app_password"]))
    if res.status_code != 200:
        logger.error("ask_agent HTTP %s: %s", res.status_code, res.text[:200])
        return "El agente no está disponible en este momento. Probá de nuevo en un rato."
    data = res.json()
    if data.get("error"):
        logger.error("ask_agent rpc error: %s", json.dumps(data["error"])[:200])
        return "El agente devolvió un error. Probá reformular el pedido."

    result = data.get("result") or {}
    if result.get("sessionId"):
        session["sessionId"] = result["sessionId"]
    parts = ((result.get("status") or {}).get("message") or {}).get("parts") or []
    text = "\n".join(
        p["text"] for p in parts if p.get("type") == "text" and isinstance(p.get("text"), str)
    ).strip()
    return text or "El agente no devolvió respuesta."


# ── Gemini Live session setup ─────────────────────────────────────────────────


def build_setup_message() -> dict:
    return {
        "setup": {
            "model": f"models/{GEMINI_MODEL}",
            "generation_config": {
                "response_modalities": ["AUDIO"],
                "speech_config": {
                    "voice_config": {"prebuilt_voice_config": {"voice_name": GEMINI_VOICE}}
                },
            },
            "input_audio_transcription": {},
            "output_audio_transcription": {},
            "system_instruction": {"parts": [{"text": build_system_instruction()}]},
            "tools": [
                {
                    "function_declarations": [
                        {
                            "name": "ask_agent",
                            "description": (
                                f"Envía el pedido del usuario al agente {AGENT_LABEL} y devuelve su "
                                "respuesta. Usala para TODA consulta de datos, registros o acciones."
                            ),
                            "parameters": {
                                "type": "OBJECT",
                                "properties": {
                                    "consulta": {
                                        "type": "STRING",
                                        "description": "El pedido del usuario, completo y fiel.",
                                    }
                                },
                                "required": ["consulta"],
                            },
                        }
                    ]
                }
            ],
        }
    }


# ── WebSocket bridge ──────────────────────────────────────────────────────────

app = FastAPI()


@app.get("/healthz")
async def healthz():
    missing = validate_config()
    return {"ok": not missing, "missing": missing, "agent": AGENT_SLUG, "model": GEMINI_MODEL}


@app.websocket("/ws/voice")
async def voice_endpoint(websocket: WebSocket):
    await websocket.accept()
    missing = validate_config()
    if missing:
        await websocket.send_json({"text": f"Gateway sin configurar: faltan {', '.join(missing)}"})
        await websocket.close(code=1008)
        return

    session = {"sessionId": None}
    audio_in = 0  # bytes of PCM uploaded (16 kHz mono s16 → 32000 B/s)
    audio_out = 0  # bytes of PCM played back (24 kHz mono s16 → 48000 B/s)
    user_text = ""
    model_text = ""

    url = f"{GEMINI_WS_URL}?key={GEMINI_API_KEY}"
    logger.info("Voice session start: agent=%s model=%s", AGENT_SLUG, GEMINI_MODEL)

    try:
        async with websockets.connect(url, max_size=16 * 1024 * 1024) as gemini_ws:
            send_lock = asyncio.Lock()

            async def send_to_gemini(payload: dict):
                async with send_lock:
                    await gemini_ws.send(json.dumps(payload))

            await send_to_gemini(build_setup_message())

            async def run_tool(call: dict):
                name = call.get("name", "")
                args = call.get("args") or {}
                call_id = call.get("id")
                if name == "ask_agent":
                    consulta = str(args.get("consulta", "")).strip()
                    logger.info("ask_agent: %s", consulta[:120])
                    await websocket.send_json({"type": "agent_thought", "text": consulta})
                    try:
                        output = await ask_agent(consulta, session)
                    except Exception as e:  # noqa: BLE001 — must always answer Gemini
                        logger.exception("ask_agent failed")
                        output = f"Error consultando al agente: {e}"
                else:
                    output = f"Herramienta desconocida: {name}"
                func_resp = {"name": name, "response": {"output": output}}
                if call_id:
                    func_resp["id"] = call_id
                await send_to_gemini({"toolResponse": {"functionResponses": [func_resp]}})

            async def forward_to_gemini():
                nonlocal audio_in
                async for message in websocket.iter_text():
                    data = json.loads(message)
                    if "audio" in data:
                        audio_in += len(data["audio"]) * 3 // 4  # b64 → bytes, close enough
                        await send_to_gemini(
                            {
                                "realtime_input": {
                                    "audio": {
                                        "mime_type": "audio/pcm;rate=16000",
                                        "data": data["audio"],
                                    }
                                }
                            }
                        )
                    elif "text" in data:
                        await send_to_gemini(
                            {
                                "client_content": {
                                    "turns": [
                                        {"role": "user", "parts": [{"text": data["text"]}]}
                                    ],
                                    "turn_complete": True,
                                }
                            }
                        )

            async def forward_from_gemini():
                nonlocal audio_out, user_text, model_text
                async for raw in gemini_ws:
                    if isinstance(raw, bytes):
                        raw = raw.decode("utf-8")
                    response = json.loads(raw)

                    if "setupComplete" in response or "setup_complete" in response:
                        await websocket.send_json({"type": "ready"})
                        continue

                    content = response.get("serverContent") or response.get("server_content")
                    if content:
                        if content.get("interrupted"):
                            await websocket.send_json({"type": "interrupted"})
                            model_text = ""
                            continue

                        in_tr = content.get("inputTranscription") or content.get(
                            "input_transcription"
                        )
                        if in_tr and in_tr.get("text"):
                            user_text += in_tr["text"]
                            await websocket.send_json(
                                {"type": "user_transcript", "text": user_text, "final": False}
                            )

                        out_tr = content.get("outputTranscription") or content.get(
                            "output_transcription"
                        )
                        if out_tr and out_tr.get("text"):
                            model_text += out_tr["text"]
                            await websocket.send_json(
                                {"type": "agent_transcript", "text": model_text, "final": False}
                            )

                        turn = content.get("modelTurn") or content.get("model_turn")
                        if turn:
                            if user_text:
                                await websocket.send_json(
                                    {"type": "user_transcript", "text": user_text, "final": True}
                                )
                                user_text = ""
                            for part in turn.get("parts", []):
                                inline = part.get("inlineData") or part.get("inline_data")
                                if inline and inline.get("data"):
                                    audio_out += len(inline["data"]) * 3 // 4
                                    await websocket.send_json({"audio": inline["data"]})

                        if content.get("turnComplete") or content.get("turn_complete"):
                            if model_text:
                                await websocket.send_json(
                                    {"type": "agent_transcript", "text": model_text, "final": True}
                                )
                                model_text = ""

                    tool_call = response.get("toolCall") or response.get("tool_call")
                    if tool_call:
                        calls = tool_call.get("functionCalls") or tool_call.get("function_calls") or []
                        for call in calls:
                            asyncio.create_task(run_tool(call))

            done, pending = await asyncio.wait(
                [
                    asyncio.create_task(forward_to_gemini()),
                    asyncio.create_task(forward_from_gemini()),
                ],
                return_when=asyncio.FIRST_COMPLETED,
            )
            for task in pending:
                task.cancel()
            for task in done:
                if not task.cancelled() and task.exception():
                    raise task.exception()
    except WebSocketDisconnect:
        pass
    except Exception:
        logger.exception("Voice session error")
        try:
            await websocket.send_json({"text": "Se cortó la sesión de voz. Recargá para reintentar."})
        except Exception:  # noqa: BLE001 — socket may already be gone
            pass
    finally:
        secs_in = audio_in / 32000.0
        secs_out = audio_out / 48000.0
        logger.info(
            "Voice session end: %.1fs in, %.1fs out, openclawp session=%s",
            secs_in,
            secs_out,
            session.get("sessionId"),
        )


# Static frontend — served last so /ws/voice and /healthz win.
STATIC_DIR = Path(__file__).parent / "static"


@app.get("/")
async def index():
    return FileResponse(STATIC_DIR / "index.html")


app.mount("/", StaticFiles(directory=str(STATIC_DIR)), name="static")


if __name__ == "__main__":
    import uvicorn

    missing = validate_config()
    if missing:
        logger.warning("Config incompleta (el WS la rechaza hasta resolverla): %s", missing)
    uvicorn.run(app, host=HOST, port=PORT)
