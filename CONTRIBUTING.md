# Contributing

Keep changes compatible with PHP 8.3 and MediaWiki 1.45.1.

CI lints PHP, JavaScript, CSS, and Markdown on pull requests and pushes to
`main`. To run the same checks locally, use PHP 8.3 or later, Composer, Node 24,
and the pnpm version in `package.json`.

```sh
composer install
pnpm install --frozen-lockfile
pnpm preflight
```

Run `composer test` for PHP syntax and coding standards, or `pnpm lint` for
JavaScript, CSS, and Markdown. MediaWiki loads the frontend assets directly
through ResourceLoader.
