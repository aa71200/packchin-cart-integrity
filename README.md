# Packchin Cart Integrity

A selected source sample from CodexaWP’s **Packchin 0.1.0** WooCommerce kit builder. It exercises the original cart transaction and quote path against native WooCommerce cart lines.

Create a local disposable WordPress site with WooCommerce and set `CODEXAWP_TEST_WP_LOAD` to its `wp-load.php`. Run `php tests/check.php` from the repository. The fixture creates and publishes a two-product kit, verifies that repeating a request adds no duplicates, changes a price, and injects a failure on the second line to verify the original cart stays intact.

Start with [the cart adapter](src/Features/Commerce.php) and [the fixture](tests/check.php). The included supporting classes are the subset used by the fixture. GPL-2.0-or-later, see [LICENSE](LICENSE).

![Cart snapshot and verification results from the WooCommerce test run](docs/cart-verification.png)

To render the same report from a fresh verification run, use `php tests/check.php --html > report.html` and open the generated file in a browser.

[Original Packchin mobile storefront (Persian demo)](docs/original-mobile-demo.png) · [Cart integrity fixture results](docs/verification.txt)
