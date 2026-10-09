<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;

/**
 * Printable 80 mm receipt (works with any thermal printer through the browser's print dialog).
 */
class ReceiptController extends Controller
{
    public function __invoke(Order $order)
    {
        $order->load(['items.addons', 'payment', 'cashier', 'refund']);

        return view('receipt', [
            'order' => $order,
            'store' => [
                'name' => Setting::get('store_name'),
                'address' => Setting::get('store_address'),
                'contact' => Setting::get('store_contact'),
                'tin' => Setting::get('store_tin'),
                'footer' => Setting::get('receipt_footer'),
                'vat_registered' => Setting::bool('vat_registered'),
            ],
        ]);
    }
}
