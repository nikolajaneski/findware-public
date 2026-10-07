# Hostinger web hosting deployment preparation

Target: the owner's existing Hostinger web hosting and findward.us. Lodgera VPS is not involved. No upload, DNS, credentials or live activation has been performed. Confirm the actual plan/site directory before deployment.

## Hosting requirements

PHP 8.4.1+ for the pinned dependency set, compatible Composer 2 CLI if using server installation, required PHP extensions (ctype, filter, hash, mbstring, openssl, session, tokenizer, json, fileinfo, iconv, dom/libxml, pcre), working outbound HTTPS through cURL or compatible streams, .htaccess rewrites, writable private storage and reliable file locks. Confirm the private sibling directory is allowed by hosting permissions/open_basedir. No SQL, migrations, Redis, queue worker, cron or permanent Node SSR process is required. Build Node version: 20.19+ or 22.12+.

## Package layout

    domain directory/
      findward-app/     app, bootstrap, config, vendor, routes, views, storage, private .env
      public_html/      index.php, .htaccess, build assets, icons, robots.txt

Only public files go in public_html. The packaged index.php uses ../findward-app and sets Laravel's public path to public_html, so Vite finds the manifest correctly. No symlink is required. Never upload the full application or environment/configuration cache under public_html. Preserve storage/framework/consultation and APP_KEY across updates.

## Finish the package locally

Use the owner's normal working PHP8.4 runner. Do not use LGP's setup/dev scripts or migrations:

    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build
    npm run check
    npm run test:browser
    npm run package:hostinger

Packaging refuses a ready-for-upload package if vendor/autoload.php or built assets are missing. The current source-only ZIP is an extraction handoff, NOT a complete runnable deployment: Composer dependencies still require installation. You may instead install the locked dependencies inside the private application directory on Hostinger after its PHP/SSH/Composer capabilities are confirmed.

PHP fixture tests are delivered and owner-deferred. A successful frontend build is not proof of PHP runtime or hosting compatibility. Before live deployment, locally verify the homepage and disabled settings endpoint with the working PHP runner; keep provider gates false. Do not use a diagnostic that calls Google.

## Separate private owner configuration and activation

After approval, create .env from .env.example privately inside findward-app, generate a NEW stable APP_KEY once, set APP_ENV=production, APP_DEBUG=false and APP_URL to the final HTTPS origin. Enter the existing OAuth client ID, client secret and refresh token only on the server; never place them in VITE_* values, public assets, logs, chat or source control. Configure only the confirmed owner calendar/schedule. Credentials and key have not been configured here.

Confirm durable OAuth status and resolve old uncertain bookings before separately approving activation. Keep invitations false. Enable booking, calls, writes and verified Meet capability only after that approval. Single-installation file locks do not coordinate simultaneously active LGP and findward endpoints; choose one live booking installation.

Use HTTPS. Exclude consultation routes from CDN/page caching. Protect and preserve private file state. If config caching is used, generate it privately AFTER server configuration; never distribute that secret-bearing cache. DNS/hosting changes and any real test booking require separate approval.

The clean /consultation page uses the existing Apache .htaccess front-controller fallback, with no physical consultation directory, extra rewrite or hash fragment. Its exact Laravel route is separate from the JSON API subpaths. Packages must contain public_html/build/consultation.html and consultation-head.html beside the homepage artifacts; the packager refuses missing page output. Both pages render without a permanent Node process. Existing rewrite/front-controller paths were checked locally; actual Hostinger runtime verification still awaits deployment authorization.
