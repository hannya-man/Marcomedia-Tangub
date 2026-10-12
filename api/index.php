<?php

// Vercel runs PHP only from api/ (vercel-php runtime, see vercel.json): every request that isn't a
// file under public/ arrives here and goes to Laravel as usual.
require __DIR__ . '/../public/index.php';
