# Ameria vPOS Gateway for WooCommerce

A simple WooCommerce payment gateway plugin for **Ameriabank vPOS 3.1** REST API.

## Features

- Ameriabank vPOS payment method for WooCommerce
- Redirects customers to the Ameria payment page
- Verifies payment with `GetPaymentDetails`
- Supports Ameria test mode
- Supports classic checkout and WooCommerce Checkout Blocks

## Requirements

- WordPress
- WooCommerce
- Ameriabank vPOS merchant credentials

## Installation

Upload the plugin folder to:

```txt
wp-content/plugins/ameria-vpos-woocommerce/
````

Or install it from WordPress Admin:

```txt
Plugins → Add New → Upload Plugin
```

Then activate the plugin.

## Configuration

Go to:

```txt
WooCommerce → Settings → Payments → Ameria vPOS
```

Enable the payment method and fill in:

* Client ID
* Username
* Password
* Base URL
* Currency
* Payment page language

For Ameria test environment, the base URL is usually:

```txt
https://servicestest.ameriabank.am/VPOS
```

For live mode, use the production URL provided by Ameriabank.

## Test Mode

Enable **Ameria test mode** when using Ameriabank sandbox credentials.

In test mode, the plugin sends:

```txt
OrderID: 30299001 - 30300000
Amount: 10 AMD
Currency: 051
```

For production, disable test mode.

## Checkout

The plugin supports:

* Classic WooCommerce checkout shortcode
* WooCommerce Checkout Blocks

Classic checkout shortcode:

```txt
[woocommerce_checkout]
```

## License

MIT License.
