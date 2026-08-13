# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.9] - Admin download route fix, enable-all-products toggle

### Fixed
- The admin order-view Download button built its URL with the frontend route id (`orderattachments/...`), which is not registered on the admin router, so every download returned the admin 404 page. It now uses the registered admin route id (`panth_orderattachments/...`).

### Added
- "Enable for all products unless disabled per product" toggle (default off, preserving current behavior). When enabled, products whose `panth_allow_order_attachment` attribute is unset show the upload widget and only an explicit "No" hides it; applied to both the product-page block and the server-side upload validation.

## [1.0.8]

### Changed
- Replaced typographic characters (em dashes, curly quotes, ellipsis) with plain ASCII punctuation. No functional changes.

## [1.0.7]

### Changed
- Code cleanup: removed redundant inline comments and docblocks from the PHP source. No functional changes.

## [1.0.6] - 2026-06-18

### Changed
- README rewritten to match gold-template structure: Quick Answer block,
  Who Is It For, Key Features grouped by area, Configuration table sourced
  directly from system.xml, How It Works, Admin Management, FAQ, Support
  table with product page link, Quick Links table, and SEO keyword footer.
- Canonical URL updated to the live product page.
- Removed unverified claims (panth_quote_attachment table reference, REST API
  endpoints). Documented only features confirmed in system.xml, db_schema.xml,
  and controller/block inspection.

## [1.0.5] - Upload extension deny-list (defense-in-depth)

### Added
- `Controller/Upload/Save` now calls `Panth\Core\Security\UploadExtensionPolicy::assertSafeExtension()`
  before saving, as a hard executable deny-list (`php`, `phtml`, `sh`, `jsp`, ...)
  independent of the admin-configurable allowed-extensions field. Even if an
  admin typed `php` into that field it can no longer take effect. Requires
  `mage2kishan/module-core ^1.0.17`. Behaviour for legitimate uploads is
  unchanged (files are still stored under a random sha256 name and served only
  via the ownership-gated download controller).

## [1.0.0] - Initial release

### Added - product page upload widget
- Drag-and-drop file upload zone with real-time progress bars and
  thumbnail previews (images) or file-type badges (documents).
- Per-product enable/disable via the `panth_allow_order_attachment`
  EAV attribute (Boolean, installed by data patch).
- Configurable: allowed extensions, max file size, max files per item,
  custom upload label.
- Honeypot field + rate limiting (20 uploads per 10 minutes per
  customer/session) for bot protection.
- Full Alpine.js implementation for Hyva themes, vanilla JS for Luma.

### Added - cart and checkout integration
- After-plugin on `Magento\Checkout\Controller\Cart\Add` links
  uploaded attachments to the quote item.
- After-plugin on `Magento\Checkout\Controller\Cart\UpdateItemOptions`
  preserves, adds, or removes attachments on cart edit.
- Rich attachment cards (thumbnails, filenames, notes) stored as
  `additional_options` on the quote item - visible in cart, minicart,
  and checkout order summary.
- Hyva: styled HTML cards with image thumbnails and lightbox links.
- Luma: plain-text summary (Luma strips HTML attributes).

### Added - order placement
- Observer on `sales_order_place_after` copies quote-item attachments
  to order-item attachments (sets `order_id` and `order_item_id`).
- Frontend "My Orders > View Order" page shows grouped attachment
  cards with download links and lightbox for images.

### Added - admin
- Order view: "Order Attachments" section with file details table
  and download actions.
- Dedicated admin grid (Sales > Panth Infotech > Order Attachments)
  with thumbnail, filename, product link, order ID, customer, file
  size, extension, status, dates, and download action.
- ACL resources: `Panth_OrderAttachments::attachment_view` and
  `Panth_OrderAttachments::attachment_download`.

### Added - security
- Stored filenames use SHA-256 hash (never user-supplied names on
  disk).
- Server-side file extension whitelist and max file size enforcement.
- Ownership validation on download and thumbnail endpoints.
- Soft-delete (status flag) - files are never hard-deleted by
  customers.

### Added - lightbox
- Global lightbox script for image attachment popups - works in cart,
  minicart, checkout, and order view on both Hyva and Luma.

### Quality
- Constructor injection only - zero `ObjectManager::getInstance()`
  usage anywhere in the module.
- All PHP files pass MEQP (Magento2 coding standard) with zero errors.
- Composer validate passes.

### Compatibility
- Magento Open Source / Commerce / Cloud 2.4.4 - 2.4.8
- PHP 8.1, 8.2, 8.3, 8.4
- Hyva themes and Luma themes

---

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422
