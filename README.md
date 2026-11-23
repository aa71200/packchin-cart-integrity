# Packchin Cart Integrity

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/packchin-cart-integrity/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/packchin-cart-integrity/actions/workflows/checks.yml)

A failed kit item should leave the cart as it was, and a repeated request should leave it with one copy. This fixture exercises the original **Packchin 0.1.0** cart transaction and quote path against native WooCommerce cart lines.

## Cart verification preview

<p align="center">
  <a href="docs/cart-verification.png"><img src="docs/cart-verification.png" width="900" alt="Two native WooCommerce cart lines and four passing Packchin test checks"></a>
  <br>
  <em>Native WooCommerce cart snapshot and the four recorded fixture checks</em>
</p>

<details>
<summary>View the original mobile storefront</summary>

<p align="center">
  <a href="docs/original-mobile-demo.png"><img src="docs/original-mobile-demo.png" width="360" alt="Packchin's original Persian mobile storefront"></a>
  <br>
  <em>Original RTL storefront · mobile fixture</em>
</p>

</details>

## Run the fixture

Create a local disposable WordPress site with WooCommerce and set `CODEXAWP_TEST_WP_LOAD` to its `wp-load.php`. Run `php tests/check.php` from the repository. The fixture creates and publishes a two-product kit, verifies that repeating a request adds no duplicates, changes a price, and injects a failure on the second line to verify the original cart stays intact.

Start with [the cart adapter](src/Features/Commerce.php) and [the fixture](tests/check.php). The included supporting classes are the subset used by the fixture. GPL-2.0-or-later, see [LICENSE](LICENSE).

To render the same report from a fresh verification run, use `php tests/check.php --html > report.html` and open the generated file in a browser.

[Cart integrity fixture results](docs/verification.txt)
