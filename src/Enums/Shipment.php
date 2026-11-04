<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Enums;

/**
 * Delivery method of the order ("order.shipment").
 */
enum Shipment: int
{
    case Courier = 0;

    /** Collection point, e.g. UPS Access Point or DHL Parcel Shop. */
    case CollectionPoint = 1;

    case ParcelLocker = 2;

    /** Parcel delivered to a RUCH kiosk. */
    case Kiosk = 3;

    /** Pickup in store (click and collect). */
    case StorePickup = 4;
}
