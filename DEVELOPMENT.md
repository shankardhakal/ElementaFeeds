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
