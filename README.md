ElementaFeeds Description
Overview
ElementaFeeds is a scalable, workflow-driven product syndication platform designed to process large, multilingual product feeds (CSV, XML, JSON) and syndicate them to destination websites (e.g., WooCommerce, WordPress) via REST APIs. The architecture is modular, service-oriented, and optimized for high-volume asynchronous processing, with a production-ready admin UI and a robust import pipeline. Built on Laravel with Backpack for the admin interface, it prioritizes simplicity, reliability, and extensibility.
Technology Stack

Backend Framework: Laravel (Latest Stable Version)
Admin Panel: Backpack for Laravel (Free Version)
Database: MySQL 8+
Queue Driver: Redis
Server Environment: CloudPanel on Linux
Monitoring: Laravel Native Supervisor 
Development Environment: Laravel Sail or Docker for local development
Deployment: Zero-downtime deployments with Laravel Envoy (optional)

System Architecture
ElementaFeeds follows a modular, service-oriented architecture with clear separation of concerns, enabling scalability and extensibility. The system is organized into layers: presentation (admin UI), application logic (services and controllers), background processing (jobs), and data storage (database models).
Directory Structure
app/
├── Console/           # CLI commands for health checks and diagnostics
├── Http/
│   ├── Controllers/   # Backpack admin and API controllers
│   └── Middleware/    # Route throttling and security middleware
├── Jobs/              # Queueable jobs for feed processing
├── Models/            # Eloquent models for data persistence
├── Services/          # Core business logic (e.g., FilterService, TransformationService)
├── Observers/         # Model observers for event handling
└── Providers/         # Service providers for bootstrapping
resources/views/       # Blade templates for admin UI and wizards
routes/                # Web and Backpack routes
database/              # Migrations, seeders, and factories
config/feeds.php       # Configuration for chunk sizes and processing parameters
DEVELOPMENT.md         # Development plan and roadmap
README.md              # Project overview and setup guide

Key Layers and Components

Presentation Layer (Admin UI)  

Purpose: Provides a user-friendly interface for managing feeds, websites, and connections.
Components:
Connection Setup Wizard: A multi-step interface (Source → Preview & Filter → Mapping → Destination → Schedule) for configuring feed-to-website connections, built with Blade templates and Backpack.
Connections Dashboard: A central hub displaying connections with sortable columns, advanced filtering, pagination, and CSV export functionality. Shows metrics like Created/Updated/Skipped/Failed records.
CRUD Interfaces: Backpack-powered interfaces for managing Networks, Feeds, Websites, and Connections, with consistent layouts and accessibility features (ARIA labels, keyboard navigation).


Key Features:
Responsive design with mobile compatibility.
Auto-submit filters, debounced search, and real-time status polling.
Disabled "Run Now" button during active imports to prevent duplicates.
Production-ready as of January 17, 2025, with a mandate to avoid further modifications.




Application Logic Layer  

Purpose: Handles business logic, API interactions, and user input processing.
Components:
Controllers:
ConnectionController: Manages the connection setup wizard, CRUD operations (create, edit, clone, delete), and import initiation (runNow).
FeedCrudController: Handles feed management with custom actions like cleanup.
DashboardController: Provides endpoints for metrics and error log downloads.


Services:
FilterService: Applies mapping-wizard rules to filter out invalid products during import.
TransformationService: Maps source fields to API payloads, validates essential fields (URL, price, name), and handles category normalization.
CategoryNormalizer: Processes category mappings with configurable source fields and delimiters, ensuring robust handling of mixed formats.
SyndicationService: Manages product creation/update logic for destination APIs.
WooCommerceApiClient / WordPressApiClient: Interfaces with destination APIs for product imports and category/attribute fetching.


Middleware:
Route throttling (throttle:1,1) on import endpoints to prevent rapid-fire requests.
CSRF and API token protection for security.






Background Processing Layer (Job Pipeline)  

Purpose: Handles asynchronous feed processing, chunking, and syndication with resilience and scalability.
Components:
StartImportRunJob: Initializes an import run, creates an import_runs record, and dispatches the job pipeline with concurrency protection (lockForUpdate, Cache::lock).
DownloadFeedJob: Downloads feed files (CSV, XML, JSON) to temporary storage, samples 10 rows to check filter applicability, and skips empty runs.
ChunkFeedJob: Streams feeds into chunks (100–500 records), applies FilterService to skip unmapped products, and writes JSON chunks to disk.
ProcessChunkJob: Processes chunks, applies transformations, and syndicates products via API. Includes circuit breaker, dynamic batch sizing, and exponential backoff retries.
HandleImportCompletionJob: Marks successful runs as "completed" and triggers cleanup.
HandleImportFailureJob: Cancels failed batches, purges pending jobs, and logs errors.
CleanupImportRunChunksJob: Deletes temporary chunk files in all success/failure scenarios.
DeleteFeedProductsJob: Handles UI-driven feed deletion by removing products from destination sites.


Resilience Features:
Circuit Breaker: Monitors API failures, adjusts batch sizes, and pauses processing during outages.
Dynamic Batch Sizing: Starts with conservative sizes (50 for creates, 25 for updates/deletes) and splits on timeouts.
Exponential Backoff: Retries with delays (1s → 2s → 4s, max 3 attempts) for HTTP 504 or cURL timeouts.
Atomic Counters: Uses database transactions to update created_records, updated_records, skipped_records, and failed_records.




Data Layer  

Purpose: Persists data and enforces integrity for feeds, websites, connections, and import runs.
Database Schema:
networks: Stores affiliate networks (e.g., Awin) with id, name, and timestamps.
feeds: Stores source feeds with id, network_id, name, feed_url, language, is_active, delimiter, enclosure.
websites: Stores destination sites with id, name, url, platform (enum: woocommerce, wordpress), language, woocommerce_credentials, wordpress_credentials, connection_status, last_checked_at.
feed_website: The "Connection" pivot table with id, feed_id, website_id, name, is_active, JSON fields (filtering_rules, category_mappings, attribute_mappings, field_mappings, update_settings), category_source_field, category_delimiter, schedule, last_run_at.
import_runs: Logs import executions with id, feed_website_id, status (enum), processed_records, created_records, updated_records, deleted_records, log_messages (text).
jobs, batches: Standard Laravel tables for queue management.


Optimizations:
Indexes on name, is_active, and composite fields for performance.
Unique constraint on import_runs(feed_website_id, status) to prevent concurrent imports.
JSON fields for flexible configuration storage.
Streaming for large CSVs to avoid memory overload.





Data Flow (End-to-End Import Pipeline)

User Initiation: Admin triggers an import via the UI (ConnectionController@runNow).
StartImportRunJob: Creates an import_runs record with "pending" status, using a database transaction and Cache::lock to prevent duplicates.
DownloadFeedJob: Downloads the feed to a temporary file, samples 10 rows via FilterService, and skips if no records pass filters.
ChunkFeedJob: Streams the feed into JSON chunks (100–500 records), applies FilterService to filter out unmapped products, and dispatches ProcessChunkJob for each chunk.
ProcessChunkJob:
Performs API health checks (WooCommerceApiClient::checkApiHealth).
Parses chunk, applies FilterService and TransformationService to map and validate products.
Generates SKUs, splits into create/update batches, and uses createBatchWithBackoff for API calls.
Handles timeouts via batch splitting and retries, logs errors to error_records, and updates atomic counters.


HandleImportCompletionJob: Marks the run as "completed" and triggers cleanup.
HandleImportFailureJob: Cancels the batch, logs errors, and marks the run as "failed".
CleanupImportRunChunksJob: Deletes temporary chunk files.
Dashboard Updates: Displays real-time metrics (Created/Updated/Skipped/Failed) and downloadable error logs.
