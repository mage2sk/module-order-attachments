# Magento 2 Order Attachments

Panth Order Attachments lets customers upload files for a specific product before they add it to the cart. The files stay linked to the cart line item, follow it through checkout and, once the order is placed, are attached to the order item. Customers see their files on the order page in their account; store staff download them from the admin order view or from a dedicated attachments grid. Typical uses are artwork for print products, prescriptions, reference images and documents that belong to one line item.

The module adds an upload widget to the product page (and to the cart "edit item" page), stores the files outside the web root under `var/panth/order-attachments/`, serves them only through access-checked controllers, records each file in its own database table and writes an "Attachments" line into the quote item options so the files are listed with the item in the cart, mini cart, checkout summary and order. Both the Hyva theme (Alpine.js template) and the Luma theme (plain JavaScript template) are supported.

Product page: [kishansavaliya.com/magento-2-order-attachments.html](https://kishansavaliya.com/magento-2-order-attachments.html)

## Features

- Upload widget on the product page with drag and drop, file browser, per-file progress bar, thumbnails for images and a file-type badge for other files.
- Optional customer note that is saved with the uploaded files.
- Per-product control through the "Allow Order Attachments" product attribute (`panth_allow_order_attachment`: Use Config Setting, Yes or No), plus an "Enable for All Products" switch that decides for every product left on "Use Config Setting".
- Widget only shows on saleable products and only while the module is enabled.
- Server-side validation: form key, file extension (configurable allow list plus the executable-type deny list from `Panth\Core\Security\UploadExtensionPolicy`; SVG, HTML, XML and script types are always refused), file content (the detected MIME type must match the extension, images must be readable images, HTML/script/PHP content is refused), file size and product permission. The templates apply the same extension, size and file-count limits before uploading.
- Honeypot field and a rate limit of 20 uploads per 10 minutes (by customer ID for logged-in customers, by the uploads recorded in the session for guests).
- Files are stored under a random 40-character hex name in `var/panth/order-attachments/<product_id>/`; the original file name is kept in the database and used for downloads.
- Attachments are linked to the quote item after "Add to Cart" and re-linked (or unlinked) when the customer edits the item from the cart. Only attachments owned by the current visitor and uploaded for the same product are linked.
- On order placement an observer stores the order ID and order item ID on each attachment.
- "Order Attachments" section on the customer's order view page, grouped by product, with thumbnails, notes and download links.
- Lightbox for image attachments shown in the cart and mini cart on Hyva; it closes with Escape or the Close button and returns focus to the thumbnail.
- "Order Attachments" section on the admin order view page with a download link per file.
- Admin grid ("Manage Attachments") with preview (image thumbnail or a file-type icon), file name, product link, order ID, customer email, status, upload date and a download action; type, size and note columns are available under Columns.
- Keyword search on the attachments grid (file name, customer email, file extension, order ID).
- Separate ACL resources for viewing the grid, downloading files and changing the configuration.
- Soft delete: removing a file before checkout sets its status to 0; files that belong to a placed order cannot be removed from the storefront.

## Compatibility

| Platform | Versions |
|---|---|
| Magento Open Source | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8 (as published on the product page) |
| Adobe Commerce | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8 (as published on the product page) |
| PHP | >=8.1 (from `composer.json`) |
| Themes | Hyva (Alpine.js template) and Luma (plain JavaScript template) |

Composer constraints on Magento packages: `magento/framework` ^103.0, `magento/module-catalog` ^104.0, `magento/module-sales` ^103.0, `magento/module-quote` ^101.0, `magento/module-checkout` ^100.4, `magento/module-eav` ^102.0, `magento/module-customer` ^103.0, `magento/module-store` ^101.1, `magento/module-media-storage` ^100.4, `magento/module-backend` ^102.0, `magento/module-ui` ^101.2.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4 to 2.4.8.
- PHP 8.1 or newer.
- `mage2kishan/module-core` ^1.0.17 (module `Panth_Core`). It provides the theme detection helper, the upload extension policy and the "Panth Infotech" admin menu that this module hooks into.
- The Magento modules listed under Compatibility (all part of a standard Magento installation).

## Installation

```bash
composer require mage2kishan/module-order-attachments
bin/magento module:enable Panth_Core Panth_OrderAttachments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`setup:di:compile` is only needed when the store runs in production mode. The module ships no files under `view/*/web`, so no static content deployment is required for it.

Check that the module is enabled:

```bash
bin/magento module:status Panth_OrderAttachments
```

`setup:upgrade` creates the `panth_order_attachment` table and the "Allow Order Attachments" product attribute (attribute group "Order Attachments", global scope, default "Use Config Setting").

## Configuration

Admin path: Stores > Configuration > Panth Extensions > Order Attachments. The same page is reachable from the admin menu entry Order Attachments > Configuration. All settings can be set at default, website and store view scope.

Section: "Order Attachments" (`panth_orderattachments`).

### General Settings

| Setting | Default | What it does |
|---|---|---|
| Enable Order Attachments | Yes | Master switch. When No, the upload widget, the lightbox script and the upload endpoint are disabled. |
| Enable for All Products | No | Shown only when the module is enabled. Applies to products whose "Allow Order Attachments" attribute is "Use Config Setting": Yes shows the widget on them, No hides it. Products set to Yes always show the widget and products set to No never do. |

Config paths: `panth_orderattachments/general/enabled`, `panth_orderattachments/general/enable_all_products`.

### Upload Settings

| Setting | Default | What it does |
|---|---|---|
| Allowed File Extensions | pdf,jpg,jpeg,png,gif,doc,docx,zip | Comma-separated list of extensions accepted by the uploader. Required. Extensions blocked by `Panth\Core\Security\UploadExtensionPolicy` (executable and script types) are rejected even if listed here. |
| Maximum File Size (MB) | 10 | Maximum size per file in megabytes. Required, whole number greater than zero. Checked in the browser and again on the server; the PHP `upload_max_filesize` and `post_max_size` limits still apply. |
| Maximum Files Per Item | 3 | Maximum number of files per cart line item. Required, whole number greater than zero. Enforced by the upload widget and again on the server when files are linked to a cart item (add to cart and cart item update); extra files are left unlinked and the customer sees a warning. |

Config paths: `panth_orderattachments/upload/allowed_extensions`, `panth_orderattachments/upload/max_file_size`, `panth_orderattachments/upload/max_files_per_item`.

### Display Settings

| Setting | Default | What it does |
|---|---|---|
| Upload Button Label | Attach Files | Text shown on the upload button and as the widget heading. When empty, "Attach Files" is used. |

Config path: `panth_orderattachments/display/upload_label`. The "Attachments" item option is always written to the cart item and is shown wherever Magento prints item options (cart, mini cart, checkout summary, order).

### Per-product setting

Catalog > Products > edit product > attribute group "Order Attachments" > "Allow Order Attachments" (Use Config Setting, Yes or No; default Use Config Setting). The attribute has global scope.

## Usage

### Customer: product page and cart

1. On a product that allows attachments, the customer drops files onto the widget or picks them with the file browser. Each file is uploaded immediately by an AJAX POST to `orderattachments/upload/save` and gets a database record with status 1 and no cart item yet. The widget shows a counter (for example "2/3"), a progress bar per file, an image thumbnail or an extension badge, and a text field for a note.
2. A file can be removed again before checkout (POST to `orderattachments/upload/delete`); this sets the record status to 0. The file stays on disk. `orderattachments/upload/listing?product_id=<id>` returns the current visitor's active uploads for that product that are not yet linked to a cart item or order.
3. When the customer clicks "Add to Cart", the attachment IDs and the note are posted with the form. A plugin on `Magento\Checkout\Controller\Cart\Add` links the records to the new quote item, stores the note on each record and adds an "Attachments" entry to the item's additional options. On Hyva the entry is HTML with thumbnails (opened in a lightbox); on Luma it is a text list of file names.
4. Editing the item from the cart opens the product page with the current files preloaded. A plugin on `Magento\Checkout\Controller\Cart\UpdateItemOptions` links newly added files, unlinks removed ones and rewrites the "Attachments" option. Submitting the edit with no files removes the option.
5. On order placement (`sales_model_service_quote_submit_success`) the observer `Observer\CopyAttachmentsToOrder` writes the order ID and order item ID to each active attachment of the ordered quote items.

### Customer: account order view

My Account > My Orders > View Order shows an "Order Attachments" section below the order information, grouped by product, with image thumbnails, file type and size, the note and a download link (`orderattachments/download/index?id=<attachment_id>`).

### Admin

- Sales > Orders > order view: an "Order Attachments" section (container `order_additional_info`) lists file name with type and size, product (linked to the product edit page), uploaded by (customer email, "Customer #<id>" or "Guest"), note, date and a download link.
- Admin menu Panth Infotech > Order Attachments > Manage Attachments: a UI grid over the `panth_order_attachment` table with filters, column control, bookmarks and paging. Status shows "Active" (1) or "Deleted" (0). The Actions column offers Download (`panth_orderattachments/attachment/download`); image previews are loaded through `panth_orderattachments/attachment/preview`.
- There is no delete action in the admin. Deleted (status 0) records and their files remain on disk.

### Storage and access control

- New files are written to `var/panth/order-attachments/<product_id>/<random-hex>.<ext>`, which is not served by the web server. The stored name comes from a cryptographically random generator; the original name is only kept in the database. Files uploaded by earlier versions to `pub/media/panth/order-attachments/` are moved to the same relative path under `var/` by the data patch `MoveLegacyUploadsToPrivateStorage` during `bin/magento setup:upgrade`; the stored paths in `panth_order_attachment` are relative, so the download links keep working and the old direct media URLs return 404. Copies of those files held in database media storage (`media_storage_file_storage`) are also written to `var/` and removed from the database. A file that cannot be moved is logged and left in place; the controllers still find it there (they look in `var/` first, then `pub/media/`). The module itself does not build direct media URLs.
- Database media storage: attachments are always kept on the local filesystem in `var/panth/order-attachments/` and are never copied into database media storage, whatever "Media Storage" is set to. On a setup with several web nodes, `var/panth/order-attachments/` must be on storage shared by all nodes.
- Ownership: an upload made by a logged-in customer belongs to that customer. A guest upload belongs to the session it was made in (its ID is kept in the checkout session) and to any session whose current quote contains the quote item it is linked to. An attachment with an order ID belongs to the customer who owns the order. For a guest order, the visitor who opened that order through Orders and Returns (the store's guest order lookup) can also view and download its attachments.
- Storefront download (`orderattachments/download/index`) is allowed for the owner as described above; order attachments require the logged-in order owner. The file is always sent as `application/octet-stream` with `Content-Disposition: attachment` and the original file name.
- Storefront thumbnail (`orderattachments/thumbnail/view`) serves only JPEG, PNG, GIF, WebP and BMP files to the owner, with `X-Content-Type-Options: nosniff` and a sandboxing Content-Security-Policy. Everything else gets a 404.
- Storefront upload and removal require a valid form key. Removal (`orderattachments/upload/delete`) is refused for attachments that already have an order ID and for attachments not owned by the current visitor.
- Admin download requires the ACL resource `Panth_OrderAttachments::attachment_download`; admin preview requires `Panth_OrderAttachments::attachment_view`.
- The module does not add email templates. The "Attachments" item option is stored with the quote and order item and is rendered wherever Magento prints item options.

### Templates that can be overridden

| Template | Used for |
|---|---|
| `view/frontend/templates/product/view/upload.phtml` | Upload widget on Hyva (Alpine.js); applied through `default_hyva.xml` |
| `view/frontend/templates/luma/product/view/upload.phtml` | Upload widget on Luma (plain JavaScript); block `product.order.attachments.upload`, alias `form_top` inside `product.info` |
| `view/frontend/templates/lightbox.phtml` | Lightbox script added before the closing body tag on every storefront page while the module is enabled |
| `view/frontend/templates/order/view/attachments-frontend.phtml` | "Order Attachments" section on the customer order view |
| `view/adminhtml/templates/order/view/attachments.phtml` | "Order Attachments" section on the admin order view |

Colour tokens for the widget are defined in `etc/theme-config.json` and registered with `Panth\Core\ViewModel\ThemeConfig` through `etc/frontend/di.xml`.

## Developer Notes

- Module name: `Panth_OrderAttachments`; Composer package: `mage2kishan/module-order-attachments`; namespace: `Panth\OrderAttachments`.
- Load sequence: after `Panth_Core`, `Magento_Catalog`, `Magento_Sales`, `Magento_Quote`, `Magento_Checkout`.
- Frontend route `orderattachments` (controllers `Upload/Save`, `Upload/Delete`, `Upload/Listing`, `Download/Index`, `Thumbnail/View`).
- Admin route `panth_orderattachments` (controllers `Adminhtml/Attachment/Index`, `Adminhtml/Attachment/Download`, `Adminhtml/Attachment/Preview`).
- Access and storage helpers: `Model\AttachmentAccess` (ownership checks), `Model\FileLocator` (resolves a stored path in `var/` or, for older files, `pub/media/`), `Model\Upload\FileValidator` (extension, size and content checks), `Model\LegacyUploadMigrator` (moves files uploaded by earlier versions out of `pub/media/`; used by the data patch `Setup\Patch\Data\MoveLegacyUploadsToPrivateStorage`).
- Plugins (`etc/frontend/di.xml`): `Plugin\Cart\LinkAttachmentsAfterAddToCart` (after `Magento\Checkout\Controller\Cart\Add::execute`) and `Plugin\Cart\UpdateAttachmentsOnCartUpdate` (after `Magento\Checkout\Controller\Cart\UpdateItemOptions::execute`).
- Observer (`etc/events.xml`, global scope): `Observer\CopyAttachmentsToOrder` on `sales_model_service_quote_submit_success`.
- Model `Model\OrderAttachment` (event prefix `panth_order_attachment`), resource model `Model\ResourceModel\OrderAttachment`, collection `Model\ResourceModel\OrderAttachment\Collection`, grid collection `Model\ResourceModel\OrderAttachment\Grid\Collection` (registered as `panth_orderattachments_listing_data_source` in `etc/di.xml`).
- Blocks: `Block\Product\View\Upload` (widget; `shouldShow()`, `getUploadConfig()`, `getExistingAttachments()`), `Block\Order\View\Attachments` (customer order view), `Block\Adminhtml\Order\View\Attachments` (admin order view).
- Helper `Helper\Config` wraps all configuration paths.
- UI grid `panth_orderattachments_listing` with column classes `Ui\Component\Listing\Column\Thumbnail`, `FileSize`, `ProductName` and `Actions`.
- Data patch `Setup\Patch\Data\AddAllowOrderAttachmentAttribute` creates the product attribute `panth_allow_order_attachment`; `Setup\Patch\Data\UseConfigForAllowOrderAttachment` turns it into a select with the source model `Model\Product\Attribute\Source\AllowOrderAttachment` (empty value = Use Config Setting, 1 = Yes, 0 = No) and resets stored No values to Use Config Setting.
- ACL resources: `Panth_OrderAttachments::config` (configuration section), `Panth_OrderAttachments::attachment_view` (grid and menu), `Panth_OrderAttachments::attachment_download` (admin download).
- Database table (`etc/db_schema.xml`): `panth_order_attachment` with columns `attachment_id`, `quote_item_id`, `order_item_id`, `order_id`, `product_id`, `customer_id`, `customer_email`, `original_filename`, `stored_filename`, `file_path`, `file_size`, `mime_type`, `file_extension`, `customer_note`, `status`, `created_at`, `updated_at`; indexes on `quote_item_id`, `order_item_id`, `order_id`, `product_id` and `status`.
- Request parameters used by the templates: `order_attachment_ids[]` and `order_attachment_note` on the add-to-cart and update-item forms; `product_id`, `file`, `customer_note` and the honeypot `oa_website_url` on the upload request.

## Uninstallation

```bash
bin/magento module:disable Panth_OrderAttachments
composer remove mage2kishan/module-order-attachments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The following remain after removal and must be cleaned up by hand if no longer wanted: the `panth_order_attachment` table, the configuration rows under `panth_orderattachments/` in `core_config_data`, the product attribute `panth_allow_order_attachment`, the uploaded files under `var/panth/order-attachments/` and `pub/media/panth/order-attachments/`, and the "Attachments" entries stored in the additional options of existing quote and order items.

## Support

- Product page: [kishansavaliya.com/magento-2-order-attachments.html](https://kishansavaliya.com/magento-2-order-attachments.html)
- Contact form: [kishansavaliya.com/contact](https://kishansavaliya.com/contact)
- Email: kishansavaliyakb@gmail.com
- Bug reports: [GitHub issues](https://github.com/mage2sk/module-order-attachments/issues)

## Documentation

[USER_GUIDE.md](USER_GUIDE.md) is written for store administrators and covers installation, verifying the module is active, every configuration setting, enabling attachments on products, how customers use the widget in the product page, cart, cart edit and checkout, the admin order view and attachments grid, the customer order view, and troubleshooting.

## License

Free and open source under the MIT License. See [LICENSE.txt](LICENSE.txt).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Links

- Website: [kishansavaliya.com](https://kishansavaliya.com)
- All extensions: [kishansavaliya.com/magento-extensions.html](https://kishansavaliya.com/magento-extensions.html)
- GitHub: [mage2sk/module-order-attachments](https://github.com/mage2sk/module-order-attachments)
- Packagist: [mage2kishan/module-order-attachments](https://packagist.org/packages/mage2kishan/module-order-attachments)
