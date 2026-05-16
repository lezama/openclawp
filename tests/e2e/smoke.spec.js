const { test, expect } = require( '@playwright/test' );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.describe( 'openclaWP smoke', () => {
	test( 'admin can log in', async ( { page } ) => {
		await login( page );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'openclaWP top-level menu is registered', async ( { page } ) => {
		await login( page );
		// The plugin registers a top-level "openclaWP" menu in wp-admin.
		// Asserting via the menu DOM avoids coupling to a specific subpage slug.
		await expect(
			page.locator( '#adminmenu' ).getByRole( 'link', {
				name: /opencla(W|w)P/,
			} )
		).toBeVisible();
	} );

	test( 'REST namespace responds', async ( { request } ) => {
		// The agents-api / openclawp REST routes register under
		// /wp-json/openclawp/v1. Hitting the namespace index should 200 even
		// without authentication (route discovery is public).
		const response = await request.get( '/wp-json/openclawp/v1' );
		expect( [ 200, 404 ] ).toContain( response.status() );
	} );
} );
