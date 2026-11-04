<?php
/*
/https://acs.ispledger.cc/backend/api/rest/parameters.php?list_all_devices=1

https://acs.ispledger.cc/backend/api/rest/parameters.php?device_id=11


https://acs.ispledger.cc/backend/api/rest/credentials.php

https://acs.ispledger.cc/backend/api/rest/credentials.php

(cancel/retry). Let me show you how to use it with some examples:

To interact with the tasks API, you can use these patterns:

Get all tasks (with pagination):
GET /backend/api/rest/tasks.php?page=1&limit=20
Get tasks for a specific device:
GET /backend/api/rest/tasks.php?device_id=11
Get tasks with specific status:
GET /backend/api/rest/tasks.php?device_id=11&status=completed
Get a specific task by ID:
GET /backend/api/rest/tasks.php?id=1
Cancel a pending task:
POST /backend/api/rest/tasks.php
Content-Type: application/json

{
    "task_id": "123",
    "action": "cancel"
}
Retry a failed task:
POST /backend/api/rest/tasks.php
Content-Type: application/json

{
    "task_id": "123",
    "action": "retry"
}
