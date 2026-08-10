> You are in **rushing/laravel-popcorn-bubble** — a bubblewrap (`bwrap`) sandbox substrate for popcorn Runners, giving OS-namespace isolation for semi-trusted transforms with Node and Python support out-of-the-box.

This is a standalone PHP Composer package. It implements the `rushing/laravel-popcorn` `Runner`
contract using `bwrap` namespace isolation, exposing a `LanguageProvider` seam so runtimes beyond
Node/Python can be registered without touching the kernel.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
