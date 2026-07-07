Always confirm actual DB schema with Schema::hasColumn or migrate:status before
writing any code that assumes a column exists.
Subscriptions are M-Pesa only — never reintroduce Paystack into the subscription
flow, only escrow payments use Paystack.
Never edit a migration that has already run (status "Ran" in migrate:status) —
always create a new migration instead.
Controller namespaces in this project use lowercase Api\v1\..., not Api\V1\...