# Shipday Integration

A WooCommerce shipping method for stores that dispatch deliveries with [Shipday](https://www.shipday.com/). It prices delivery by **real driving distance** (via [Geoapify](https://www.geoapify.com/)) instead of straight-line distance, and offers customers a choice of delivery services at checkout.

> Not affiliated with or endorsed by Shipday. See [Shipday and this plugin](#shipday-and-this-plugin) for what is and isn't included.

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-required-7f54b3)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb3)
![Version](https://img.shields.io/badge/version-2.3.0-green)

---

## Features

- **Pickup and drop-off addresses**
  - Pickup comes from the WooCommerce **billing** fields.
  - Drop-off comes from the WooCommerce **shipping** fields.
- **Driving distance in miles**: Geoapify geocodes both addresses and calculates the driving route.
- **Five pricing formulas**, with a global mode or per-service settings.
- **Nine delivery services** offered at checkout.
- **Package add-on fee**: a fixed fee for each package above a configurable limit.
- **Saturday surcharge**: a flat amount added to the Saturday Delivery service.
- **Custom quote** for long routes, large shipments and multi-delivery jobs.
- **Same-state rule**: routes between different states or countries are rejected.
- **Order details saved** for the administrator: service, distance, price breakdown and addresses.
- **Caching**: geocoding results are cached for 24 hours and routes for 1 hour to reduce API usage.

---

## Delivery services

| Service | Pricing |
|---|---|
| Express 2-Hour Delivery | Calculated |
| Same Day Delivery | Calculated |
| Next Day Delivery | Calculated |
| Medical Materials and Legal Documents | Calculated |
| Scheduled Routes | Calculated |
| Multiple Deliveries \| Full or Half Days | **Custom quote required** |
| Mobile Public Notary (Marriage License, Legal Documents, etc.) | Calculated |
| Saturday Delivery | Calculated, plus optional Saturday surcharge |
| Standard Delivery | Calculated |

---

## Requirements

- WordPress 5.8 or newer
- WooCommerce (active)
- PHP 7.4 or newer
- A [Geoapify](https://www.geoapify.com/) API key (a free tier is available)

---

## Installation

1. Download this repository as a ZIP, or clone it into `wp-content/plugins/`:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/YOUR-USERNAME/shipday-integration.git
   ```
2. In WordPress, go to **Plugins** and activate **Shipday Integration**.
3. Add your Geoapify API key (see below).
4. Go to **WooCommerce → Settings → Shipping**, open a shipping zone, click **Add shipping method**, and choose **Shipday Integration**.
5. Configure the method (see *Settings*).

### Setting the Geoapify API key

You can set the key in either of two places:

- **Recommended:** add this line to `wp-config.php`:
  ```php
  define( 'RR_GEOAPIFY_API_KEY', 'your-api-key-here' );
  ```
- Or enter it in the shipping method settings under **Geoapify API key**.

> **Never commit your API key to GitHub.** Leave the key empty in the plugin file, and keep real keys in `wp-config.php` only.

---

## How pricing works

### Formulas

| Formula | Calculation |
|---|---|
| Formula 1 | Base price + extra-mile charges |
| Formula 2 | (Base price + extra-mile charges) × distance multiplier |
| Formula 3 | (Base price × distance multiplier) + extra-mile charges |
| Formula 4 | Discounted base price for short trips; base price plus mileage charges for longer trips |
| Formula 5 | Extra-mile charges × distance multiplier, without the base price |

**Extra-mile charges** = (driving miles − included miles) × price per extra mile, never below zero.

### Example (Formula 1)

| Setting | Value |
|---|---|
| Base price | $25.00 |
| Included distance | 10 miles |
| Price per extra mile | $0.75 |
| Actual driving distance | 13 miles |

```
$25.00 + (13 − 10) × $0.75 = $27.25
```

The delivery price is **$27.25**, before package fees or surcharges.

### Final price

```
final price = formula price (after min/max limits and quantity mode)
            + package fee
            + Saturday surcharge (Saturday Delivery only)
```

### Package fee

| Setting | Default | Notes |
|---|---|---|
| Included packages | 3 | Covered by the delivery price |
| Fee per additional package | $5.00 | Allowed range: $5 to $100 |
| Maximum packages | 100 | Above this, only a custom quote is shown |

Example: 6 packages with the defaults = 3 extra packages × $5 = **$15** added to the delivery price.

### Custom quote

Only a **"Custom Quote Required"** option is shown when:

- the driving distance is **20 miles or more** (configurable; set to `0` to disable), or
- the cart has **more than 100 packages** (configurable).

**Multiple Deliveries | Full or Half Days** always shows as a custom quote.

Custom-quote orders check out with $0.00 shipping, get an order note, and show a **CUSTOM QUOTE REQUIRED** banner in the admin order screen so you can contact the customer with a price.

### Same-state rule

If the billing (pickup) and shipping (drop-off) addresses are in different states or countries, no delivery options are returned and the customer sees an explanatory message.

---

## Settings

Found under **WooCommerce → Settings → Shipping → (zone) → Shipday Integration**.

| Setting | Description |
|---|---|
| Geoapify API key | Optional if defined in `wp-config.php` |
| Pricing mode | **Global** (one formula for all services) or **Separate** (per-service settings) |
| Base price, included distance, price per extra mile | Core pricing inputs |
| Distance multiplier | Used by Formulas 2, 3 and 5 |
| Threshold distance and discount | Used by Formula 4 |
| Minimum / maximum price | Optional price limits |
| Formula | Choose Formula 1 to 5 |
| Quantity mode | Ignore quantity, multiply the final price, or add per quantity. **Ignore** is recommended because package fees are charged separately |
| Custom quote distance | Miles at which only a custom quote is shown (default 20) |
| Included packages / Maximum packages | Package limits (defaults 3 and 100) |
| Package fee | Enable or disable, and set the fee per additional package ($5 to $100) |
| Saturday surcharge | Flat amount added to Saturday Delivery |

---

## Shipday and this plugin

This plugin handles the **checkout side**: it calculates the delivery price and records the delivery details on the order. It does **not** currently send orders to Shipday or call the Shipday API.

The data needed to create a delivery in Shipday is saved on every order: pickup and drop-off addresses, selected service, driving distance and price. You can use it to create deliveries in Shipday manually, or as the source for an automated sync.

---

## Order data

The plugin saves delivery details to each order for administrators, shown on the order edit screen:

- Selected service
- Driving distance
- Formula and price breakdown
- Additional packages and package fees
- Saturday surcharge
- Custom quote flag and reason
- Pickup and drop-off addresses

---

## Debugging

When `WP_DEBUG` is enabled, the plugin writes calculation details and API errors to the WooCommerce log. Check **WooCommerce → Status → Logs**.

Common reasons no delivery options appear:

- The Geoapify API key is missing or invalid.
- The billing or shipping address is incomplete or could not be found.
- Billing and shipping addresses are in different states or countries.

---

## Contributing

Issues and pull requests are welcome.

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-change`
3. Commit your changes and push the branch.
4. Open a pull request.

---

## Changelog

### 2.3.0
- Renamed to Shipday Integration; client branding removed from all text.
- Added a package add-on fee: configurable included packages, fee per additional package ($5 to $100) and maximum packages.
- Added custom quote: routes of 20 miles or more, more than 100 packages, and Multiple Deliveries.
- Added a same-state/country check; different-state routes are rejected with a customer message.
- Added a Saturday surcharge setting.
- Default included distance changed from 20 to 10 miles.
- Default quantity mode changed to "Ignore quantity".
- Fixed: changing only the billing address could show stale shipping prices.
- Fixed: the global quantity multiplier setting was not applied.
- Fixed: Formula 4 saved extra-mile figures that did not match the amount charged.
- Pricing code refactored to remove duplicated logic.

### 2.2.0
- Initial public version.

---

## License

Add your license here (for example GPL-2.0-or-later, the standard for WordPress plugins) and include a `LICENSE` file in the repository.

---

## Credits

Routing and geocoding by [Geoapify](https://www.geoapify.com/).
