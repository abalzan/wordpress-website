# Stage P — HTTP evidence (local, http://localhost:8080)

Captured 2026-09-26. Page 1 of each listing, 10 per page.

- `/eventos/?county=laois` -> HTTP 200, 10 event cards
- `/eventos/?cidade=laois` -> HTTP 200, 0 event cards
- `/eventos/?county=cork` -> HTTP 200, 10 event cards
- `/eventos/?cidade=portlaoise` -> HTTP 200, 7 event cards
- `/eventos/?` -> HTTP 200, 10 event cards
