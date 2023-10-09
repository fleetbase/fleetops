<?php

use Fleetbase\Models\Order;
use Fleetbase\Support\Utils; ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?= $company->name ?? ($order->internal_id ?? $order->public_id) ?> Label</title>
</head>

<style>
	.group:after {
		content: "";
		display: table;
		clear: both;
	}

	.places--display-address {
		display: block;
	}

	.places--display-address .line {
		display: block;
	}

	.places--display-address .line:first-child {
		display: block;
		font-weight: 600;
	}

	.places--display-address .line .segment {
		display: inline-block;
		margin-right: 3px;
	}

	.places--display-address .line .segment:not(:last-child):after {
		content: ", ";
	}
</style>

<body bgcolor="#f7f7f7">
	<div style="width: 420px; border: 2px #414141 solid; margin: auto;">
		<div class="group" style="border-bottom: 1px #414141 solid;">
			<div style="float:left; display:inline-block; width: 100px; height: 90px; border-right: 1px #414141 solid; text-align: center; padding: 10px;">
				<img src="data:image/png;base64,<?= Utils::notEmpty($trackingNumber) ? $trackingNumber->qr_code : $order->trackingNumber->qr_code ?>" style="width: 100%; height: 90px;">
			</div>
			<div style="display: inline-block; height: 90px;">
				<div style="padding: 10px; font-size: 20px;">
					<span style="display: block; margin-top: 15px;"><?= $company->name ?></span>
					<span style="display: block;">
						<?php
						if ($order && $order instanceof Order) {
							$order->load('purchaseRate.serviceQuote.serviceRate');
							if (isset($order->purchaseRate->serviceQuote->serviceRate)) {
								echo strtoupper($order->purchaseRate->serviceQuote->serviceRate->name);
							}
						}
						?>
					</span>
				</div>
			</div>
		</div>
		<?php
		// load payload locations
		if ($order) {
			$order->load('payload.pickup', 'payload.dropoff');
			$pickup = $pickup ?? $order->payload->pickup;
			$dropoff = $dropoff ?? $order->payload->dropoff;
		}
		?>
		<?php if ($pickup) { ?>
			<div class="group">
				<div class="group" style="border-bottom: 1px #414141 solid; padding: 15px;">
					<div style="float: left; display: inline-block; margin-right: 30px; font-weight: bold;">
						PICKUP:
					</div>
					<div class="places--display-address" style="float: left; display: inline-block">
						<div class="line"><strong><?= $pickup->name ?></strong></div>
						<div class="line"><?= $pickup->street1 ?></div>
						<?php if (!empty($pickup->street2)) { ?>
							<div class="line"><?= $pickup->street2 ?></div>
						<?php } ?>
						<div class="line">
							<?php if (!empty($pickup->neighborhood)) { ?>
								<span class="segment"><?= $pickup->neighborhood ?></span>
							<?php } ?>
							<?php if (!empty($pickup->district)) { ?>
								<span class="segment"><?= $pickup->district ?></span>
							<?php } ?>
						</div>
						<div class="line">
							<?php if (!empty($pickup->postal_code)) { ?>
								<span class="segment"><?= $pickup->postal_code ?></span>
							<?php } ?>
							<?php if (!empty($pickup->city)) { ?>
								<span class="segment"><?= $pickup->city ?></span>
							<?php } ?>
							<?php if (!empty($pickup->province)) { ?>
								<span class="segment"><?= $pickup->province ?></span>
							<?php } ?>
							<?php if (!empty($pickup->country)) { ?>
								<span class="segment"><?= $pickup->country ?></span>
							<?php } ?>
						</div>
						<?php if (!empty($pickup->phone_number)) { ?>
							<div class="line"><?= $pickup->phone_number ?></div>
						<?php } ?>
					</div>
				</div>
			</div>
		<?php } ?>
		<?php if ($dropoff) { ?>
			<div class="group">
				<div style="padding: 15px;" class="group">
					<div style="float: left; display: inline-block; margin-right: 15px; font-weight: bold;">
						DROPOFF:
					</div>
					<div class="places--display-address" style="float: left; display: inline-block">
						<div class="line"><strong><?= $dropoff->name ?></strong></div>
						<div class="line"><?= $dropoff->street1 ?></div>
						<?php if (!empty($dropoff->street2)) { ?>
							<div class="line"><?= $dropoff->street2 ?></div>
						<?php } ?>
						<div class="line">
							<?php if (!empty($dropoff->neighborhood)) { ?>
								<span class="segment"><?= $dropoff->neighborhood ?></span>
							<?php } ?>
							<?php if (!empty($dropoff->district)) { ?>
								<span class="segment"><?= $dropoff->district ?></span>
							<?php } ?>
						</div>
						<div class="line">
							<?php if (!empty($dropoff->postal_code)) { ?>
								<span class="segment"><?= $dropoff->postal_code ?></span>
							<?php } ?>
							<?php if (!empty($dropoff->city)) { ?>
								<span class="segment"><?= $dropoff->city ?></span>
							<?php } ?>
							<?php if (!empty($dropoff->province)) { ?>
								<span class="segment"><?= $dropoff->province ?></span>
							<?php } ?>
							<?php if (!empty($dropoff->country)) { ?>
								<span class="segment"><?= $dropoff->country ?></span>
							<?php } ?>
						</div>
						<?php if (!empty($dropoff->phone_number)) { ?>
							<div class="line"><?= $dropoff->phone_number ?></div>
						<?php } ?>
					</div>
				</div>
			</div>
		<?php } ?>
		<div class="group">
			<div style="text-align: center; padding: 15px 0px; border-top: 1px #414141 solid;">
				<img src="data:image/png;base64,<?= isset($trackingNumber) ? $trackingNumber->barcode : $order->trackingNumber->barcode ?>" style="height: 90px; margin-top: 10px;">
				<div><?= strtoupper(isset($trackingNumber) ? $trackingNumber->tracking_number : $order->trackingNumber->tracking_number) ?></div>
			</div>
		</div>
	</div>
</body>

</html>