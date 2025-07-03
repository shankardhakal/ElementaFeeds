# ElementaFeeds: Development Plan & Roadmap

**Version:** 1.0
**Last Updated:** 2025-07-02

This document outlines the strategic development plan for the ElementaFeeds application, covering everything from initial UI/UX to long-term scalability and extensibility.

---

### **Table of Contents**
1.  [**Immediate Next Steps**](#-immediate-next-steps)
2.  [**Phase 1: UI/UX & Admin Interface Foundation**](#-phase-1-uiux--admin-interface-foundation)
3.  [**Phase 2: Feed Parser & Data Handler**](#-phase-2-feed-parser--data-handler-frontend-to-backend-bridge)
4.  [**Technical Architecture & Scalability**](#-technical-architecture--scalability)
5.  [**Destination Website Integration**](#-destination-website-integration-phase-5)
6.  [**Modular Code Structure**](#-modular-code-structure--extensibility)
7.  [**Security & Error Handling**](#️-security-validation--error-handling)
8.  [**Testing Strategy**](#-testing-strategy)
9.  [**Deployment and Environment**](#-deployment-and-environment)
10. [**Future Growth Strategy**](#-future-growth-strategy)

---

### **🚀 Immediate Next Steps**

Based on the existing functionality, the highest priorities are to complete the core connection management features.

1.  **Implement "Edit Connection" Functionality:**
    *   **Route:** Create `GET` and `POST` routes for `connection/{id}/edit`.
    *   **Controller:** Add `edit()` and `update()` methods to `ConnectionController`.
    *   **View:** Reuse the existing wizard views, pre-populated with the connection's data. This allows for a consistent user experience without building new forms.

2.  **Implement "Delete Connection" Functionality:**
    *   **Route:** Create a `DELETE` route for `connection/{id}`.
    *   **Controller:** Add a `destroy()` method to `ConnectionController`.
    *   **UI:** Add a confirmation modal in the dashboard to prevent accidental deletion.

3.  **Implement "Clone Connection" Functionality:**
    *   **Route:** Create a `POST` route like `connection/{id}/clone`.
    *   **Controller:** Add a `clone()` method that duplicates a `FeedWebsite` record and redirects to the new connection's edit page.

---
Here’s a **comprehensive and aligned development plan
### **Phase 1: UI/UX & Admin Interface Foundation**

**Goal:** Deliver a polished, guided UI for feed connection setup and mapping—built with Backpack, Blade, and custom code only.

#### Key Features:

* **Connection Setup Wizard** (Core)

  * Step-by-step: Source → Preview & Filter → Mapping → Destination → Schedule
  * **Preview & Filter** step shows live feed data, detects malformed data, edge cases.
  * All fields use **dropdowns**, **tooltips**, **examples**, **live validation**.
  * **Destination Product Type** selection dropdown (e.g., Deal, Fashion, Listing, Furniture).
* **UI Elements:**

  * Dropdown for country/language (Europe only: EU + UK, Norway, Switzerland).
  * Auto-suggest for feed field mapping (matching headers to known destination fields).
  * Tooltip & help text on every input; **no free text unless unavoidable**.
* **Manage Connections Dashboard** (Central Hub)

  * Overview with filters for: Feed Name, Type, Product Type, Status, Country, Language.
  * Feed count, country, last run time, error logs.
  * View, Edit, Clone, or Delete connections.

### **Phase 2: Feed Parser & Data Handler (Frontend to Backend Bridge)**

**Goal:** Build scalable feed parser and preview handler optimized for edge cases and large feed sizes.

#### Feed Handling:

* Supported Formats: CSV (primary), XML, JSON.
* Auto-detect feed type on upload.
* Display first 100 rows with live warnings (missing headers, invalid characters, encoding issues).
* Basic transform filters: delimiter, encoding, remove headers/footers.

#### Feed Edge Cases:

* Handle missing/malformed fields (manual override in preview step).
* Detect duplicates, nested arrays (for XML/JSON).
* Normalize values (e.g., convert boolean "yes"/"no" → true/false).

---

## 🔧 **TECHNICAL ARCHITECTURE & SCALABILITY**

### **Feed Queue and Background Processing (Phase 3 & 4)**

* **Queue Engine:** Laravel database queue driver (no Redis).
* **Worker Pipeline:**

  * Chunking (100–500 items per chunk)
  * Throttle based on memory and time.
  * Configurable concurrency (default: 1 worker).
* **Monitoring:**

  * Laravel Telescope for queue monitoring.
  * Admin UI dashboard shows:

    * Running jobs, failures, retries.
    * Last 10 errors with exportable logs.

#### Resource Optimizations:

* Optimized for 2GB RAM / 2 CPU setup.
* Avoid memory-heavy operations (e.g., load file in stream, not into memory).
* All imports must complete in **0–1 hour** per feed under normal conditions.

---

## 🔌 **DESTINATION WEBSITE INTEGRATION (Phase 5)**

### 1. **WooCommerce (REST API)**

* Product imports via Woo REST API.
* Mapping fields: title, description, price, SKU, categories, custom fields, attributes.
* Handle multi-site variations with saved profiles.

### 2. **WordPress (REST API)**

* Import as:

  * Posts (e.g., hotel listings, deals).
  * Custom post types (based on “Destination Product Type”).
* Field mapping: title, content, meta fields, categories, tags.

### 3. **Product Types**

* Support `Destination Product Types`: Deal, Fashion, Furniture, Listing, Hotel, etc.
* Each product type has custom field mapping presets (modular & extensible).
* Mapping templates should be versionable and overrideable via config.

---

## 🧱 **MODULAR CODE STRUCTURE & EXTENSIBILITY**

* Follow **Laravel Modular Architecture** (Modules: Feed, Connection, Mapping, Queue, Destination).
* Use **Service Providers** for plugging in custom logic.
* Allow 3rd-party developers to:

  * Add new feed processors (e.g., Shopify JSON).
  * Add new destination types.
  * Hook into mapping logic or destination formatter.

---

## 🛡️ **SECURITY, VALIDATION & ERROR HANDLING**

* Admin-only access, via Laravel Backpack.
* CSRF + API token protection on all requests.
* Feed preview escapes/validates special characters (XSS-safe).
* Fail-safe for infinite loops and malformed responses.

---

## 🧪 **TESTING STRATEGY**

### Manual Testing:

* Performed by feed admin (you) after each release.
* Real-world test feeds for each product type.

### Automated Testing:

* Feature and functional tests using PestPHP.
* Queue job tests: timeouts, memory limit tests, failure recovery.
* API integration tests (Woo/WordPress endpoints).

---

## 🚀 **DEPLOYMENT AND ENVIRONMENT**

* CloudPanel with:

  * Latest PHP, Laravel, MySQL.
  * Latest free Backpack version.
* Local dev setup with Laravel Sail or Docker.
* Zero downtime deployments using Laravel Envoy (optional).
* Optional cron-based scheduling + Telescope for job tracking.

---

## 📈 **FUTURE GROWTH STRATEGY**

### Scalability Expectations:

* **Feeds:** 500–1000 (each up to 500MB).
* **Destination sites:** 200–500 now, **double within 2–3 years**.
* **Future Support:** Feed categories, vendor segmentation, per-country logic.

### Extensibility:

* Admin UI: Pluggable mapping presets, dropdown sources.
* Backend: Add-on modules (e.g., Shopify → Woo pipeline, multilingual mappings).
* Developer docs for custom processors and destinations.


## Project Blueprint: ElementaFeeds v1.0 Old version

This document outlines the complete technical and architectural plan for **ElementaFeeds**, a scalable, workflow-driven product syndication platform.

### **1. Vision & Architectural Pillars**

#### **1.1. Project Vision**

To engineer a world-class product syndication platform capable of processing thousands of large, multi-lingual product feeds and syndicating them with granular control to hundreds of destination websites. The user experience for the "Feed Manager" is paramount, prioritizing speed, clarity, and an intuitive, guided workflow.

#### **1.2. Architectural Pillars**

1.  **The "Connection" as the Central Entity:** The core of the application is the unique link between one source **Feed** and one destination **Website**. This "rich pivot" model allows each connection to have its own filtering rules, category mappings, and lifecycle settings.
2.  **Guided, "Live" UI Workflow:** The admin panel is built around a multi-step wizard for setting up connections. This interface makes live API calls to destination websites to fetch their current categories and attributes during the mapping process, ensuring data integrity.
3.  **Scalable, Queued Backend Engine:** The backend is built on a robust, queue-based system designed for high-volume, asynchronous processing. Large feed files are downloaded and split into smaller "chunks" to be processed in parallel, ensuring the system can handle massive feeds without exhausting server resources.
4.  **Simplicity and Reliability:** The technology stack prioritizes free, open-source tools and robust, simple solutions over complex ones. For example, using the database queue driver and moving to a simpler, more reliable UI for credential entry after discovering issues with more complex JavaScript solutions.

---

### **2. Technology Stack**

* **Server Environment:** CloudPanel on a standard Linux distribution.
* **Backend Framework:** Laravel (Latest Stable Version)
* **Admin Panel:** Backpack for Laravel (Free Version)
* **Database:** MySQL 8+
* **Queue Driver:** Laravel's standard `database` driver.

---

### **3. Definitive Database Schema**

The following schema is the final version, designed to support the application's architecture.

| Table                 | Column                      | Type                 | Notes & Purpose                                                                                        |
| --------------------- | --------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------ |
| **networks** | `id`, `name`, `timestamps`  |                      | Stores affiliate networks (e.g., "Awin").                                                              |
| **feeds** | `id`, `network_id`, `name`, `feed_url`, `language`, `is_active`, **`delimiter`**, **`enclosure`**, `timestamps` |                      | Stores source feeds. `delimiter` and `enclosure` were added to handle varied CSV formats.               |
| **websites** | `id`, `name`, `url`, `platform` (enum: 'woocommerce', 'wordpress'), `language`, **`woocommerce_credentials`** (text, nullable), **`wordpress_credentials`** (text, nullable), **`connection_status`**, **`last_checked_at`**, `timestamps` |                      | Stores destination sites. The `credentials` column was refactored into two separate, nullable columns for stability and clarity. `connection_status` was added for the live status indicator. |
| **feed_website** | `id`, `feed_id`, `website_id`, `name`, `is_active`, `filtering_rules` (json), `category_mappings` (json), `attribute_mappings` (json), `field_mappings` (json), `update_settings` (json), `schedule`, `last_run_at`, `timestamps` |                      | The "Connection" table. A `unique` index on (`feed_id`, `website_id`) is required.                    |
| **syndicated_products** | `id`, `feed_website_id`, `source_product_identifier`, `destination_product_id`, `last_updated_hash`, `timestamps` |                      | Tracks every syndicated product to manage updates and deletions.                                       |
| **import_runs** | `id`, `feed_website_id`, `status` (enum), `processed_records`, `created_records`, `updated_records`, `deleted_records`, `log_messages` (text), `timestamps` |                      | Tracks a single execution log.                                                                         |
| **jobs**, **batches** | (Standard Laravel Schema)   |                      | Required for the queue system. Generated by `php artisan queue:table` and `php artisan queue:batches-table`. |

---

### **4. Step-by-Step Development Plan**

This is the exact sequence of actions to build the application, incorporating all fixes and refinements from our conversation.

#### **Phase 1: Foundation (Database & Models)**

1.  **Create Migrations:** Generate all necessary migration files for the tables listed above, including the `add_format_columns_to_feeds_table` and `refactor_credentials_on_websites_table` migrations.
2.  **Create Models:** Create an Eloquent model for each table.
    * Ensure all `$fillable` properties are correct.
    * Add `$casts` for `boolean` and `datetime` fields (`last_checked_at` in `Website`, etc.).
    * Add the necessary `CrudTrait` to all models that will be managed by a Backpack CRUD controller.
    * Implement the custom encrypting/decrypting accessors and mutators for the `woocommerce_credentials` and `wordpress_credentials` fields in the `Website` model.
    * Define all model relationships (`belongsTo`, `hasMany`, `belongsToMany`). Crucially, the `latestImportRun` relationship on the `FeedWebsite` model must explicitly define its keys: `return $this->hasOne(ImportRun::class, 'feed_website_id', 'id')->latestOfMany();`.

#### **Phase 2: UI Scaffolding & Initial Setup**

1.  **Install Backpack:** `composer require backpack/crud` & `php artisan backpack:install`.
2.  **Build Standard CRUDs:**
    * Create simple Backpack CRUDs for **Networks**, **Feeds**, and **Websites**.
    * The **Website CRUD** is the most complex. It must use the "Show Both Fields" approach for credentials, with two separate textareas (`woocommerce_credentials`, `wordpress_credentials`) that are shown/hidden with a simple JavaScript toggle. The controller must not contain complex `store`/`update`/`edit` overrides.
    * The **Website CRUD** list view must contain the `connection_status` custom column that displays the status dot.
3.  **Build the Connection Dashboard:** Create the custom `ConnectionController` and the `connections_dashboard.blade.php` view to list all `feed_website` entries, including pagination.
4.  **Implement the "Test Connection" Feature:**
    * Create the `TestApiConnectionJob`.
    * Create the `WebsiteObserver` to dispatch this job whenever a `Website` is saved.
    * Register the observer in `AppServiceProvider`.

#### **Phase 3: The Connection Setup Wizard**

1.  **Build the Multi-Step UI:** Create the custom routes and controller methods (`create`, `createStep2`, etc.) in `ConnectionController`. Create the Blade views for all 4 steps in the `/resources/views/backpack/custom/wizards/` directory.
2.  **Step 1 (Source & Destination):** A simple form with dropdowns for selecting the feed and website. Implement the unique-pair validation rule here to provide immediate feedback.
3.  **Step 2 (Preview & Filter):**
    * Create the `DownloadFeedSample` job. This job **must** download the feed to a temporary local file first, then parse it to avoid "stream does not support seeking" errors.
    * The view must show the feed data in a table and provide a dynamic UI for building filter rules.
4.  **Step 3 (Mapping Editor):**
    * The `createStep3` controller method must instantiate the correct API client (`WooCommerceApiClient` or `WordPressApiClient`) and fetch live categories/attributes. It must have a `try-catch` block to display a prominent error alert in the UI if the live API call fails.
    * The view must include the advanced category mapping UI that allows the user to select a source field and delimiter and then click "Parse" to generate the mapping table via an AJAX call.
    * The controller must have the `parseCategories` method to handle this AJAX request.
5.  **Step 4 (Settings & Final Save):**
    * A simple form for update settings and schedule.
    * The `storeStep4` method is the culmination of the wizard. It gathers all data from the session and creates the final `FeedWebsite` record in the database.

#### **Phase 4: High-Performance Backend Engine (Refactor)**

This phase refactors the initial backend placeholders into a scalable system.

1.  **Set up Batching:** Run `php artisan queue:batches-table` and `migrate`.
2.  **Refactor Job Pipeline:**
    * Eliminate `SyndicateProductJob`.
    * Move the syndication logic into `ProcessChunkJob`. Its `handle` method will now loop through the products in its chunk and call the `SyndicationService` for each one.
    * Update `DownloadAndChunkJob` to use `Bus::batch()` to dispatch all `ProcessChunkJob`s as a controllable batch.
    * Implement `then()`, `catch()`, and `finally()` closures on the batch to reliably update the `import_runs` status and clean up temporary files.
3.  **Implement Progress Tracking:**
    * Update `SyndicationService` to return a status (`'created'`, `'updated'`, `'skipped'`).
    * Update `ProcessChunkJob` to collect these statuses and use an atomic `increment` to update the counters on the `import_runs` table after processing its chunk.
.