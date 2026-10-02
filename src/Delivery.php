<?php

namespace BeeInteractive\Boomerang;

enum Delivery
{
    case Delivered;
    case Rejected;
    case Retry;
}
