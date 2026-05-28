# WoW Character Viewer

Simple PHP app to fetch and display World of Warcraft character data from the Blizzard API, including:

- Talents (class/spec/hero)
- Gear and item level
- Tier set bonuses
- Mythic+ score
- Quick comparison view between two characters
- SimC export string

## Requirements

- PHP 8.0+ (7.4 may still work, but 8+ is recommended)
- PHP cURL extension enabled
- A Blizzard Developer API client ID and client secret

## 1) Create a Blizzard API key

1. Go to the Blizzard Developer Portal: https://develop.battle.net/
2. Sign in with your Battle.net account.
3. Create a new Client credentials app.
4. Copy the generated:
	 - Client ID
	 - Client Secret

Keep the secret private. Do not commit it to git.

## 2) Create your local config file

From the project root, copy the sample config to the real config file:

```bash
cp public_html/app/config-example.php public_html/app/config.php
```

Then open public_html/app/config.php and set:

- blizzard.client_id
- blizzard.client_secret
- blizzard.region (for example: eu, us, kr, tw)

You can either:

- Put credentials directly in config.php, or
- Set environment variables BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET

Note: public_html/app/config.php is git-ignored in this repo so local secrets are not committed by default.

## 3) Run locally

This project is structured for a web root at public_html.

### Option A: MAMP/Apache

Point your local host document root to:

public_html

Then open the site in your browser.

### Option B: PHP built-in server

From the repository root:

```bash
php -S localhost:8000 -t public_html
```

Then open:

http://localhost:8000

## 4) Use the app

1. Enter Character name and Realm.
2. Optional: add Compare: Name-Realm.
3. Click Fetch.

## Troubleshooting

- Error: Failed to get access token
	- Check client ID/secret in public_html/app/config.php.
	- Verify region value (eu/us/kr/tw).

- Error: API HTTP errors
	- Confirm your Blizzard app is active and using client credentials flow.
	- Verify the character and realm are valid for the selected region.

- Blank or partial data
	- Some endpoints may not return all details for every character/profile state.

## Security notes

- Never commit public_html/app/config.php.
- If credentials are accidentally committed, revoke/regenerate them in Blizzard Developer Portal.
