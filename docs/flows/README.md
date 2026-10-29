# Flow documentation

Flow documents describe end-to-end runtime behavior that crosses modules,
machines or external services.

## Index

- [Cloudbeds → ZKAccess guest PIN automation](cloudbeds-zkaccess.md)

## Required sections for new flows

Each flow should state:

1. current state and trigger;
2. authoritative source(s);
3. inputs and affected data;
4. normal transitions;
5. cancellation/reconciliation behavior;
6. failure/retry/idempotency behavior;
7. security boundaries;
8. acceptance checks.

Use Mermaid when a diagram materially improves orientation.
