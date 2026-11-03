<?php

/*
 * Where members transfer their fees. Shown on the member portal and served to the
 * mobile app through /api/v1/bills/summary.
 */
return [
    'bank_name' => 'CIMB Bank',
    'account_number' => '8605765606',
    'account_name' => 'KELAB PENTANA',
    // Under public/: the DuitNow QR poster from CIMB.
    'qr_image' => 'images/duitnow-qr.jpg',
];
