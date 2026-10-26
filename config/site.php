<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Public website (www.mossfield.ie)
    |--------------------------------------------------------------------------
    |
    | `php artisan site:build` renders resources/views/site/* with the data in
    | resources/site/data/*.json into build_dir as plain static files, which
    | are then uploaded to the Hosting Ireland account (docs/WEBSITE.md).
    | Nothing here is read by the web app itself.
    |
    */
    'build_dir' => storage_path('app/site-build'),
    'assets_dir' => resource_path('site/assets'),
    'data_dir' => resource_path('site/data'),
    'product_images_dir' => resource_path('site/product-images'),

    // Only used for the canonical link, Open Graph tags and sitemap.xml.
    // Every link inside the site is relative.
    'base_url' => rtrim(env('SITE_BASE_URL', 'https://www.mossfield.ie'), '/'),

    'name' => 'Mossfield Organic Farm',
    'tagline' => 'Organic milk, yoghurt and farmhouse cheese from Clareen, Co. Offaly',

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Ordered. Drives the navigation, the sitemap and the link-check test.
    | 404.html is deliberately absent: it must not be in the sitemap.
    |
    */
    'pages' => [
        'index.html' => 'Home',
        'products.html' => 'Products',
        'stockists.html' => 'Where to buy',
        'awards.html' => 'Awards',
        'contact.html' => 'Contact',
    ],

    // Old 2013 file names still linked from elsewhere → 301 in .htaccess.
    'redirects' => [
        'Products.html' => 'products.html',
        'wheretobuy.html' => 'stockists.html',
        'contactus.html' => 'contact.html',
    ],

    'contact' => [
        'email' => 'info@mossfield.ie',
        'phone' => '057 913 1002',
        'office' => '085 106 3765',
        'address' => ['Mossfield Organic Farm', 'Clareen', 'Birr', 'Co. Offaly', 'Ireland'],
        'eircode' => null,
        'facebook' => 'https://www.facebook.com/MossfieldOrganicCheese',
        'map' => 'https://www.google.com/maps/search/?api=1&query=Mossfield+Organic+Farm+Clareen+Birr+Offaly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Awards
    |--------------------------------------------------------------------------
    |
    | Static content with no database future, so it lives here rather than
    | behind a source. Images are file names in resources/site/assets/images.
    |
    */
    'awards' => [
        [
            'title' => 'Waitrose Trophy',
            'event' => 'Specialist Cheesemakers Association',
            'detail' => 'Best cheese in the Specialist Cheesemakers section.',
            'image' => 'ica-2015-trophy.jpg',
        ],
        [
            'title' => 'Gold',
            'event' => 'International Cheese Awards 2015',
            'detail' => 'Mossfield Organic Farmhouse Cheese.',
            'image' => 'ica-2015-gold.jpg',
        ],
        [
            'title' => 'Silver and two Bronze',
            'event' => 'International Cheese Awards 2015',
            'detail' => 'Silver for best hard cheese, bronze for best cheese with additives (Mediterranean) and bronze for best organic cheese.',
            'image' => 'ica-2015-silver-bronze.jpg',
        ],
        [
            'title' => 'Silver',
            'event' => 'British Cheese Awards 2015',
            'detail' => 'Mossfield Farmhouse Cheese and Mossfield Mature in the Modern British category.',
            'image' => 'bca-2015-silver.jpg',
        ],
        [
            'title' => 'Bronze',
            'event' => 'British Cheese Awards 2015',
            'detail' => 'Mossfield Mediterranean in the flavour added category, and Mossfield Farmhouse Cheese.',
            'image' => 'bca-2015-bronze.jpg',
        ],
        [
            'title' => 'CÁIS Irish Cheese Awards 2015',
            'event' => 'Irish Farmhouse Cheesemakers',
            'detail' => 'Mossfield Organic Farmhouse Cheese.',
            'image' => 'cais-2015.jpg',
        ],
    ],
];
