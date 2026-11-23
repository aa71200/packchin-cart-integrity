# Packchin Cart Integrity

**What to try.** Run the WooCommerce fixture to verify that a failed kit addition restores the cart and a retried request leaves one copy.

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/packchin-cart-integrity/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/packchin-cart-integrity/actions/workflows/checks.yml)

A failed kit item should leave the cart as it was, and a repeated request should leave it with one copy. This fixture exercises the original **Packchin 0.1.0** cart transaction and quote path against native WooCommerce cart lines.

## Cart verification preview

<p align="center">
  <a href="docs/cart-verification.png"><img src="docs/cart-verification.png" width="900" alt="Two native WooCommerce cart lines and five passing Packchin test checks"></a>
  <br>
  <em>Native WooCommerce cart snapshot and five recorded fixture checks</em>
</p>

<details>
<summary>View the mobile kit builder</summary>

<p align="center">
  <a href="docs/original-mobile-demo.png"><img src="docs/original-mobile-demo.png" width="360" alt="Packchin mobile kit builder after selecting a camera for the workshop kit"></a>
  <br>
  <em>English test storefront at mobile width</em>
</p>

</details>

## Run the fixture

Create a local disposable WordPress site with WooCommerce and set `CODEXAWP_TEST_WP_LOAD` to its `wp-load.php`. Run `php tests/check.php` for a guest and `php tests/check.php --customer` for a signed-in shopper. The fixture covers replay, a changed price and a failure on the second kit line. It reads the saved session after failure and checks the product data, quantities and coupon state. The signed-in fixture also checks the persistent cart.

Start with [the cart adapter](src/Features/Commerce.php) and [the fixture](tests/check.php). The included supporting classes are the subset used by the fixture. GPL-2.0-or-later, see [LICENSE](LICENSE).

To render the same report from a fresh verification run, use `php tests/check.php --html > report.html` and open the generated file in a browser.

[Cart integrity fixture results](docs/verification.txt)
