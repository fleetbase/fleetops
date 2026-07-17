<?php

function liveOrderQuerySource(): string
{
    return file_get_contents(__DIR__ . '/../src/Support/LiveOrderQuery.php');
}

test('active live order query uses the same active status rules as the map overlay', function () {
    $source = liveOrderQuerySource();

    expect($source)
        ->toContain("public static array \$activeExcludedStatuses = ['created', 'completed', 'expired', 'order_canceled', 'canceled', 'pending']")
        ->and($source)->toContain("if (\$active)")
        ->and($source)->toContain("whereHas('driverAssigned')")
        ->and($source)->toContain("whereNotIn('status', static::\$activeExcludedStatuses)");
});

test('live order query requires renderable payload and tracking data', function () {
    $source = liveOrderQuerySource();

    expect($source)
        ->toContain("whereHas('payload'")
        ->and($source)->toContain("whereHas('waypoints')")
        ->and($source)->toContain("orWhereHas('pickup')")
        ->and($source)->toContain("orWhereHas('dropoff')")
        ->and($source)->toContain("whereHas('trackingNumber')")
        ->and($source)->toContain("whereHas('trackingStatuses')");
});
