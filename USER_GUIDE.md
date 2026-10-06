# Panth Order Attachments - User Guide

This guide walks a Magento store administrator through every screen
and setting of the Panth Order Attachments extension. No coding required.

---

## Table of contents

1. [Installation](#1-installation)
2. [Verifying the extension is active](#2-verifying-the-extension-is-active)
3. [Configuration](#3-configuration)
4. [Enabling attachments on products](#4-enabling-attachments-on-products)
5. [How customers use it](#5-how-customers-use-it)
6. [Admin order view](#6-admin-order-view)
7. [Admin attachments grid](#7-admin-attachments-grid)
8. [Customer order view](#8-customer-order-view)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Installation

### Composer (recommended)

```bash
composer require mage2kishan/module-order-attachments
bin/magento module:enable Panth_Core Panth_OrderAttachments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Manual zip

1. Download the extension package zip
2. Extract to `app/code/Panth/OrderAttachments`
3. Make sure `app/code/Panth/Core` is also present
4. Run the same `module:enable ... cache:flush` commands above

---

## 2. Verifying the extension is active

After installation, these should work:

1. **Configuration page** - Stores > Configuration > Panth Extensions > Order Attachments is reachable
2. **Admin grid** - Sales > Panth Infotech > Order Attachments is reachable
3. **Product attribute** - edit any product and look for the "Order Attachments" attribute group

If any of these fail, see [Troubleshooting](#9-troubleshooting).

---

## 3. Configuration

Navigate to **Stores > Configuration > Panth Extensions > Order Attachments**.

### General group

| Setting | Default | What it does |
|---|---|---|
| **Enable Module** | Yes | Master on/off switch. When disabled, no upload widget appears and all upload endpoints return errors. |

### Upload group

| Setting | Default | What it does |
|---|---|---|
| **Allowed Extensions** | `jpg,jpeg,png,gif,pdf,doc,docx,zip` | Comma-separated list of allowed file extensions. Both server-side and client-side validation. |
| **Max File Size (MB)** | 10 | Maximum file size in megabytes per file. |
| **Max Files Per Item** | 3 | Maximum number of attachments a customer can attach per cart item. Checked in the browser and again on the server when files are linked to the cart item. |

### Display group

| Setting | Default | What it does |
|---|---|---|
| **Upload Label** | Attach Files | The heading shown above the upload widget on the product page. |

---

## 4. Enabling attachments on products

Each product has an **Allow Order Attachments** attribute with three
values:

- **Use Config Setting** (default): follows **Enable for All Products**
  in the configuration.
- **Yes**: always shows the upload widget.
- **No**: never shows the upload widget.

To change it:

1. Open **Catalog > Products > Edit Product**
2. Scroll to the **Order Attachments** attribute group
3. Set **Allow Order Attachments** to the value you want
4. Save the product

---

## 5. How customers use it

### Product page

When a product has attachments enabled, a drag-and-drop upload zone
appears below the product options:

1. Customer drags files or clicks "browse" to select files
2. Files upload immediately via AJAX with progress bars
3. Image files show thumbnail previews; other files show type badges
4. Customer can optionally add a note (up to 500 characters)
5. Customer can remove files before adding to cart
6. When the customer clicks "Add to Cart", the attachment IDs are
   submitted with the form

### Cart

Uploaded attachments appear as a styled card under the product in the
cart (Hyva) or as plain text (Luma), showing thumbnails, filenames,
and any customer note.

### Cart edit

When a customer edits cart item options, the existing attachments are
pre-loaded. The customer can add more, remove some, or update the note.

### Checkout

Attachment info is visible in the checkout order summary.

### After order placement

Attachments are linked to the order. On the "My Orders > View Order"
page, grouped attachment cards are shown with:
- Thumbnail or file-type badge
- Filename, extension, and file size
- Customer note (if provided)
- Download link

---

## 6. Admin order view

Open any order in **Sales > Orders > View**.

An **Order Attachments** section appears showing a table with:
- File name (with icon)
- Product (linked to product edit page)
- Size
- Type (extension badge)
- Uploaded by (customer email or "Guest")
- Note
- Date
- Download action

---

## 7. Admin attachments grid

Navigate to **Sales > Panth Infotech > Order Attachments**.

The grid shows all attachments across all orders with columns:
- Thumbnail (image preview or file-type badge)
- Original Filename
- Product Name (linked to product edit page)
- Order ID
- Customer Email
- File Size (formatted)
- File Extension
- Status
- Created At / Updated At
- Actions (Download)

The grid supports filtering, sorting, and export.

---

## 8. Customer order view

On the storefront, customers can view their attachments on the
**My Orders > View Order** page. Each product's attachments are
grouped together with thumbnails, download links, and notes.

Image attachments open in a lightbox when clicked. Press Escape or the Close button to close it.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Upload widget not showing | Product attribute set to No, or Use Config Setting while Enable for All Products is No | Edit product > Order Attachments > Allow Order Attachments = Yes, or set Enable for All Products = Yes |
| Upload widget not showing | Module disabled | Stores > Configuration > Panth Extensions > Order Attachments > Enable = Yes |
| Upload fails with "file type not allowed" | Extension not in the allowed list | Stores > Configuration > Upload > Allowed Extensions |
| Upload fails with "file too large" | File exceeds configured max size | Increase Max File Size in config, also check PHP `upload_max_filesize` and `post_max_size` |
| "Too many uploads" error | Rate limit reached | Wait 10 minutes, or increase the limit in code |
| Attachments not showing on order | Observer not fired | Clear cache, recompile DI, verify `events.xml` is loaded |
| Download returns 404 | File deleted from disk | Check `var/panth/order-attachments/` (new uploads) and `pub/media/panth/order-attachments/` (older uploads) |

---

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422

Response time: 1-2 business days for paid licenses.
