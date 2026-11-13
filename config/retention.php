<?php

return [
    /*
    | Optional content-addressed archive disk. Leave null to preserve the
    | database-only retention behavior. In production this should point at an
    | operator-managed immutable/WORM-capable disk such as S3 Object Lock.
    */
    'archive_disk' => env('ERP_RETENTION_ARCHIVE_DISK'),
];
