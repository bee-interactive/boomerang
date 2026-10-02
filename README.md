# Boomerang

Boomerang notifier for the Laravel PHP framework. Monitor and report Laravel errors.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require bee-interactive/boomerang
```

Add the endpoint and token of the site to `.env`:

```dotenv
BOOMERANG_ENDPOINT=https://example.com/api/boomerang/my-site
BOOMERANG_TOKEN=your-site-token
```

Boomerang stays inactive until both are set. Check the setup with:

```bash
php artisan boomerang:test
php artisan about --only=boomerang
```

To customise the configuration:

```bash
php artisan vendor:publish --tag=boomerang-config
```

## Revision

Write the deployed commit to a `REVISION` file at the root of the application, for example in a GitHub Actions deploy:

```bash
echo $GITHUB_SHA > REVISION
```

## How it works

- Every exception the application reports is sent, except those Laravel does not report (`dontReport`, validation, 404…).
- During a request, reports are sent once the response has been sent. In commands and jobs, they are sent right away.
- The same failure is sent at most once every 10 seconds; the occurrences in between are counted.
- When the endpoint cannot be reached, reports are kept in `storage/boomerang/spool` and sent with the next successful report.
- Reporting never throws and never slows down the response.
- Nothing is reported while the application runs its tests.

## Data

Each report contains the exception chain with its stack trace and the code around the application frames, the request (method, URL, route, input, user agent, referer, language, anonymised IP), the running command or job, the identifier of the signed-in user, and the environment (revision, PHP, Laravel and package versions).

Input keys containing `password`, `token`, `secret`, `key`, `card`, `cvv`, `iban`, `avs` or `ahv` are masked before anything leaves the application. Cookies and the `Authorization` header are never sent.

## Testing

```bash
composer test
composer test:coverage
composer analyse
composer format
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
