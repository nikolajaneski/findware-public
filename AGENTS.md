# findward project rules

Standalone public marketing and consultation booking only. No client/workspace database, auth/admin, migrations, queues or LGP engine.
Keep the public design, copy, motion, visitor timezone and booking behavior intact. Every Google write remains behind explicit owner-controlled gates. Invitations are unsupported.
Keep credentials server-only. Never commit .env, APP_KEY, tokens, runtime state or configuration caches. Keep private application files outside public_html.
Run the frontend build and relevant mocked checks before packaging. PHP checks are owner-deferred until a working approved runner is available.
Do not change C:\Projects\LGP. No commits, live Google calls, uploads, DNS, activation or deployment without separate authorization.
