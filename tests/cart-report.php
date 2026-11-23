<?php
/** HTML view of the original Packchin fixture's verified WooCommerce cart snapshot. */
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Packchin Cart Integrity</title>
	<style>
		* {box-sizing:border-box} body {margin:0;background:#edf4f1;color:#203a32;font:15px/1.5 system-ui,sans-serif}
		main {max-width:1030px;margin:auto;padding:clamp(12px,3.4vw,35px)}
		header {background:linear-gradient(118deg,#193a32,#2a6253);color:white;border-radius:15px;padding:26px 30px;display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:end}
		header p {margin:0;color:#dae5fb} h1 {font-size:clamp(25px,4vw,40px);letter-spacing:-.044em;line-height:1.08;margin:5px 0 0}
		.panels {display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:14px;margin-top:15px}
		section {min-width:0;background:#fff;border:1px solid #d2e2da;border-radius:14px;padding:20px} h2 {margin:0 0 12px;font-size:18px}
		table {width:100%;border-collapse:collapse;text-align:start;font-size:14px}
		th,td {padding:8px 5px;border-block-end:1px solid #e1e8f4;text-align:start} th {color:#435578;font-weight:650}
		td:last-child,thead th:last-child {text-align:end;white-space:nowrap}
		li {padding:8px 0;border-block-end:1px solid #e1e8f4}
		ul {list-style:none;margin:0;padding:0}
		.result {font-size:11px;font-weight:700;padding:2px 6px;border-radius:5px;color:#17513e;background:#e3f3ea;margin-inline-end:8px;display:inline-block}
		@media(max-width:690px){.panels{grid-template-columns:1fr} header{padding:16px} section{padding:14px}}
	</style>
</head>
<body>
<main>
	<header><div><p>Packchin test store</p><h1>Cart integrity</h1></div><p><?php echo esc_html( sprintf( '%d WooCommerce checks passed', count( $checks ) ) ); ?></p></header>
	<div class="panels">
		<section>
			<h2>Initial cart snapshot</h2>
			<table>
				<thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Line total</th></tr></thead>
				<tbody><?php foreach ( $snapshot as $line ) : ?>
					<tr><th scope="row"><?php echo esc_html( $line['data']->get_name() ); ?></th><td><?php echo esc_html( (string) $line['quantity'] ); ?></td><td><?php echo wp_kses_post( wc_price( $line['line_total'] ) ); ?></td></tr>
				<?php endforeach; ?></tbody>
			</table>
		</section>
		<section>
			<h2>Verification results</h2>
			<ul><?php foreach ( $checks as $check ) : ?><li><span class="result">Pass</span><?php echo esc_html( $check ); ?></li><?php endforeach; ?></ul>
		</section>
	</div>
</main>
</body>
</html>
