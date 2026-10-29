<?php
/**
 * Account messages inbox.
 *
 * Variables are set by MyEasyBuy_Customer_Messages::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var array{tab: string, q: string, per: int, active: bool}     $filters
 * @var array{rows: array<int, array<string, mixed>>, total: int, pages: int} $result
 * @var array{all: int, unread: int}                               $counts
 * @var array<string, string>                                     $tabs
 * @var int                                                        $page
 */

defined( 'ABSPATH' ) || exit;

$rows     = $result['rows'];
$is_empty = empty( $rows );
$jump     = MyEasyBuy_Customer_Messages::page_url( array(), 9999 );
?>
<div class="meb-msg">
	<h2 class="meb-msg__title"><?php esc_html_e( '我的消息', 'myeasybuy-core' ); ?></h2>

	<div class="meb-msg__toolbar">
		<div class="meb-msg__tabs" role="tablist" aria-label="<?php esc_attr_e( '消息分类', 'myeasybuy-core' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<?php $count = 'unread' === $key ? $counts['unread'] : $counts['all']; ?>
				<a class="meb-msg__tab<?php echo $filters['tab'] === $key ? ' is-active' : ''; ?>" role="tab" href="<?php echo esc_url( MyEasyBuy_Customer_Messages::page_url( array( 'tab' => $key ), 1 ) ); ?>"<?php echo $filters['tab'] === $key ? ' aria-selected="true"' : ' aria-selected="false"'; ?>>
					<?php echo esc_html( $label . ' (' . $count . ')' ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<form class="meb-msg__search" method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Messages::get_endpoint_url() ); ?>">
			<?php if ( 'all' !== $filters['tab'] ) : ?>
				<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
			<?php endif; ?>
			<?php if ( 20 !== $filters['per'] ) : ?>
				<input type="hidden" name="meb_per" value="<?php echo esc_attr( (string) $filters['per'] ); ?>">
			<?php endif; ?>
			<span class="meb-msg__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
			</span>
			<input type="search" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( '包裹号/订单号/商品名称', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '包裹号/订单号/商品名称', 'myeasybuy-core' ); ?>">
		</form>
	</div>

	<form id="meb-msg-bulk" class="meb-msg__bulk" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="myeasybuy_messages_update">
		<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
		<input type="hidden" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>">
		<input type="hidden" name="meb_per" value="<?php echo esc_attr( (string) $filters['per'] ); ?>">
		<input type="hidden" name="meb_paged" value="<?php echo esc_attr( (string) $page ); ?>">
		<?php wp_nonce_field( 'myeasybuy_messages_update' ); ?>
	</form>

	<div class="meb-msg__board">
		<?php if ( $is_empty ) : ?>
			<div class="meb-msg__empty">
				<div class="meb-msg__mail" aria-hidden="true">
					<svg viewBox="0 0 160 130">
						<path d="M28 46h104l-52 36z" fill="#f7f9fc"/>
						<path d="M28 46v62h104V46L80 82z" fill="#e7eef6"/>
						<path d="M28 108l38-28 14 10 14-10 38 28z" fill="#d7e1ee"/>
						<path d="M28 46l52 36L132 46" fill="none" stroke="#c5d0e0" stroke-width="2"/>
						<rect x="58" y="58" width="36" height="4" rx="2" fill="#f4f7fb"/>
						<rect x="58" y="68" width="28" height="4" rx="2" fill="#f4f7fb"/>
						<circle cx="118" cy="78" r="16" fill="#ff7e21" opacity="0.18"/>
						<circle cx="118" cy="78" r="12" fill="#ff7e21"/>
						<path d="M112.5 78.5l8-4.2-3.2 9.2-1.6-3.4z" fill="#fff"/>
					</svg>
				</div>
				<p>
					<?php
					echo esc_html(
						$filters['active']
							? __( '没有符合条件的消息', 'myeasybuy-core' )
							: __( '您没有未读的消息。', 'myeasybuy-core' )
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<ul class="meb-msg__list">
				<?php foreach ( $rows as $row ) : ?>
					<?php $unread = empty( $row['read'] ); ?>
					<li class="meb-msg__row<?php echo $unread ? ' is-unread' : ''; ?>">
						<label class="meb-msg__check">
							<input form="meb-msg-bulk" type="checkbox" name="message_ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" class="meb-msg__box" aria-label="<?php esc_attr_e( '选择', 'myeasybuy-core' ); ?>">
						</label>
						<div class="meb-msg__main">
							<p class="meb-msg__subject">
								<?php if ( $unread ) : ?>
									<span class="meb-msg__dot" aria-hidden="true"></span>
								<?php endif; ?>
								<?php echo esc_html( (string) $row['title'] ); ?>
							</p>
							<?php if ( '' !== (string) $row['body'] ) : ?>
								<p class="meb-msg__excerpt"><?php echo esc_html( (string) $row['body'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== (string) $row['order_number'] || '' !== (string) $row['product'] ) : ?>
								<p class="meb-msg__meta">
									<?php
									$bits = array();
									if ( '' !== (string) $row['order_number'] ) {
										$bits[] = sprintf(
											/* translators: %s: order number */
											__( '订单号 %s', 'myeasybuy-core' ),
											(string) $row['order_number']
										);
									}
									if ( '' !== (string) $row['product'] ) {
										$bits[] = (string) $row['product'];
									}
									echo esc_html( implode( ' · ', $bits ) );
									?>
								</p>
							<?php endif; ?>
						</div>
						<time class="meb-msg__time" datetime="<?php echo esc_attr( (string) $row['created_at'] ); ?>"><?php echo esc_html( (string) $row['created_at'] ); ?></time>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div class="meb-msg__bar">
			<div class="meb-msg__actions">
				<label class="meb-msg__all">
					<input type="checkbox" data-meb-msg-all<?php disabled( $is_empty ); ?>>
					<?php esc_html_e( '全选', 'myeasybuy-core' ); ?>
				</label>
				<button form="meb-msg-bulk" type="submit" class="meb-msg__text" name="meb_do" value="read" data-meb-msg-read disabled><?php esc_html_e( '阅读', 'myeasybuy-core' ); ?></button>
				<button form="meb-msg-bulk" type="submit" class="meb-msg__text" name="meb_do" value="delete" data-meb-msg-delete disabled><?php esc_html_e( '删除', 'myeasybuy-core' ); ?></button>
			</div>

			<div class="meb-msg__pager">
				<form method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Messages::get_endpoint_url() ); ?>">
					<?php if ( 'all' !== $filters['tab'] ) : ?>
						<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
					<?php endif; ?>
					<?php if ( '' !== $filters['q'] ) : ?>
						<input type="hidden" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>">
					<?php endif; ?>
					<label class="meb-msg__per">
						<select name="meb_per" aria-label="<?php esc_attr_e( '每页条数', 'myeasybuy-core' ); ?>" onchange="this.form.submit()">
							<?php foreach ( MyEasyBuy_Customer_Messages::per_page_options() as $option ) : ?>
								<option value="<?php echo esc_attr( (string) $option ); ?>"<?php selected( $filters['per'], $option ); ?>><?php echo esc_html( $option . '/page' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</form>

				<?php if ( $page > 1 ) : ?>
					<a class="meb-msg__step" href="<?php echo esc_url( MyEasyBuy_Customer_Messages::page_url( array(), $page - 1 ) ); ?>" aria-label="<?php esc_attr_e( '上一页', 'myeasybuy-core' ); ?>">&lt;</a>
				<?php else : ?>
					<span class="meb-msg__step is-disabled" aria-hidden="true">&lt;</span>
				<?php endif; ?>

				<span class="meb-msg__current"><?php echo esc_html( (string) $page ); ?></span>

				<?php if ( $page < $result['pages'] ) : ?>
					<a class="meb-msg__step" href="<?php echo esc_url( MyEasyBuy_Customer_Messages::page_url( array(), $page + 1 ) ); ?>" aria-label="<?php esc_attr_e( '下一页', 'myeasybuy-core' ); ?>">&gt;</a>
				<?php else : ?>
					<span class="meb-msg__step is-disabled" aria-hidden="true">&gt;</span>
				<?php endif; ?>

				<form class="meb-msg__goto" method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Messages::page_url( array(), 1 ) ); ?>" data-meb-msg-jump="<?php echo esc_url( $jump ); ?>" data-meb-msg-pages="<?php echo esc_attr( (string) $result['pages'] ); ?>">
					<label>
						<?php esc_html_e( 'Go to', 'myeasybuy-core' ); ?>
						<input type="text" inputmode="numeric" value="<?php echo esc_attr( (string) $page ); ?>" aria-label="<?php esc_attr_e( '跳转页码', 'myeasybuy-core' ); ?>">
					</label>
				</form>
			</div>
		</div>
	</div>
</div>
