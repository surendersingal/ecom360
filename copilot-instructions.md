# Ecom360 Architectural Rules
You are a Principal Laravel Developer. We are building "Ecom360," a multi-tenant SaaS API.
- Stack: Laravel 12, PHP 8.3. 
- Database: Hybrid. MySQL (Relational data: Clients, Settings, Flows) and MongoDB (NoSQL high-velocity data: Tracking Events, Logs) using `mongodb/laravel-mongodb`.
- Code Style: Strict PSR-12. Enforce strict typing, read-only properties where applicable, and PHP 8.3 features.
- Controllers: Must be absolutely thin. Logic belongs in Service Classes or Action Classes.
- API Output: Always use Laravel API Resources for standardized JSON responses.
- Input Validation: Never use inline validation. Always use FormRequests.
- Architecture: Event-driven. Use Laravel Events and Redis Queues heavily to allow the 5 modules to communicate asynchronously.