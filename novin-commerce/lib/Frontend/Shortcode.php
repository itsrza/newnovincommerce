<?php

namespace MobinDev\Novin_Commerce\Frontend;

use MobinDev\Novin_Commerce\Common\SettingAPI;
use MobinDev\Novin_Commerce\Plugin;

class Shortcode {
	/** @var Plugin */
	private $plugin;
	private $atts;

	const TRANSACTIONS_SHORTCODE = 'novincommerce-transactions';

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	private function hooks() {
		add_shortcode( self::TRANSACTIONS_SHORTCODE, array( $this, 'transactionsShortcode' ) );
	}

	public function transactionsShortcode( $attributes ) {
		ob_start()
		?>
		<script>
			if (<?php echo is_user_logged_in() ? '0' : '1' ?>) {
				window.location.replace('<?php echo esc_js( wp_login_url( get_the_permalink() ) ); ?>');
			}

		</script>
		<?php
		if ( ! is_user_logged_in() ) {
			return ob_get_clean();
		}
		$this->atts = shortcode_atts( array( 'per_page' => 10 ), $attributes, self::TRANSACTIONS_SHORTCODE );

		$this->enqueueAssets();

		$this->showTransactions();

		return ob_get_clean();
	}

	public function enqueueAssets() {
		wp_enqueue_style(
			$this->plugin->get_plugin_name() . '-shortcode',
			$this->plugin->getFrontStyleUrl() . 'shortcode.min.css',
			[],
			$this->plugin->get_version()
		);
		wp_enqueue_script(
			$this->plugin->get_plugin_name() . '-shortcode',
			$this->plugin->getFrontScriptUrl() . 'shortcode.min.js',
			[],
			$this->plugin->get_version()
		);
	}

	public function showTransactionsOld() {
		?>
		<div id="novincommerce_transactions">
			<?php
			//            $username = '3253';

			$user_id = 11;
			//			$user_id = wp_get_current_user()->ID;
			//						$user_id = wp_get_current_user()->user_login;
			$page_number = 1;
			$per_page    = 10;

			if ( get_query_var( 'page' ) ) {
				$maybe_page_number = (int) get_query_var( 'page' );
				if ( $maybe_page_number > 0 ) {
					$page_number = $maybe_page_number;
				}
			}
			$api_link = sprintf(
//				'http://webservice.novinp.ir/SiteManagement/wpApi/GetGardeshHesabsAsync/%d/%s/0/0/%d/%d',
				'http://webservice.novinp.ir/ReportByUserName/%d/%s/0/0/%d/%d',
				$this->plugin->getAccessCode(),
				$user_id,
				$per_page,
				$page_number
			);
			$result   = wp_remote_get( $api_link, array(
				'timeout'   => 30,
				'sslverify' => true
			) );
			if ( $result instanceof \WP_Error ) {
				//var_dump($username,$api_link,$result);
				?>
				<div class="alert alert-warning text-center" role="alert">
					خطایی در دریافت اطلاعات رخ داد.
				</div>
				<?php
			} elseif ( $result['response']['code'] !== 200 or empty( $result['body'] ) ) {
				?>
				<div class="alert alert-warning text-center" role="alert">
					اطلاعاتی یافت نشد. (<?php echo $result['response']['code'] ?>)
				</div>
				<?php
			} else {
//				var_dump($result);
				?>
				<table id="mobile-table" class="table">
					<thead>
					<tr>
						<th scope="col">تاریخ</th>
						<th scope="col">شرح سند</th>
						<th scope="col">بستانکار</th>
						<th scope="col">بدهکار</th>
						<th scope="col">مانده حساب</th>
						<th scope="col">عملیات</th>
					</tr>
					</thead>
					<tbody>
					<?php
					$decoded_result = json_decode( $result['body'] );
					$total_page     = $decoded_result->TotalPages;
					unset( $result, $api_link, $username );
					$transactions = $decoded_result->Items;
					//var_dump($transactions);
					if ( is_array( $transactions ) && count( $transactions ) > 0 ) {
						foreach ( $transactions as $transaction ) {
							$item = $transaction->Item;
							?>
							<tr>
								<td data-title="تاریخ"><?php echo $item->DateSh ?></td>
								<td data-title="شرح سند"><?php echo $item->AutoDescriptionShort ?></td>
								<td data-title="بستانکار"><?php echo $item->CreditForDisplay ?></td>
								<td data-title="بدهکار"><?php echo $item->DebitForDisplay ?></td>
								<td data-title="مانده حساب"><?php echo $item->RemForDisplay ?></td>
								<td data-title="عملیات">
									<button class="btn btn-outline-primary btn-sm"
											data-toggle="modal"
											data-target="#more<?php echo $item->AsGuid ?>">
										مشاهده کامل
									</button>
								</td>
							</tr>
							<?php
							//create tafsili array

						}
					} else {
						?>
						<tr>
							<th colspan="6">تراکنشی وجود ندارد</th>
						</tr>
						<?php
					}
					?>
					</tbody>
				</table>
				<?php
				if ( $total_page > 1 ) {
					if ( $page_number > $total_page ) {
						$page_number = $total_page;
					}
					$previous = $page_number - 1 ? $page_number - 1 : 1;
					$next     = ( ( $page_number + 1 ) >= $total_page ) ? $total_page : $page_number + 1;
					?>
					<nav aria-label="Page navigation example">
						<ul class="pagination justify-content-center">
							<li class="page-item <?php echo $page_number == 1 ? 'disabled' : '' ?>">
								<a class="page-link" href="?page=<?php echo $previous ?>">قبلی</a>
							</li>
							<?php
							for ( $i = 1; $i <= $total_page; $i ++ ) {
								?>
								<li class="page-item <?php echo $page_number == $i ? 'active' : '' ?>">
									<a class="page-link" href="?page=<?php echo $i ?>"><?php echo $i ?></a>
								</li>
								<?php
							}
							?>
							<li class="page-item <?php echo $page_number == $total_page ? 'disabled' : '' ?>">
								<a class="page-link" href="?page=<?php echo $next ?>">بعدی</a>
							</li>
						</ul>
					</nav>
					<?php
				}

				if ( is_array( $transactions ) && count( $transactions ) > 0 ) {
					foreach ( $transactions as $transaction ) {
						$item = $transaction->Item;
						?>
						<!-- Modal -->
						<div class="modal fade" id="more<?php echo $item->AsGuid ?>" tabindex="-1" role="dialog"
							 aria-labelledby="exampleModalLabel" aria-hidden="true">
							<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document">
								<div class="modal-content">
									<div class="modal-header pb-0">
										<h5 class="modal-title"
											id="exampleModalLabel"><?php echo $item->AutoDescriptionShort ?></h5>
										<button type="button" class="close red" data-dismiss="modal" aria-label="Close">
											<span aria-hidden="true">&times;</span>
										</button>
									</div>
									<div class="modal-body">
										<?php
										$tafsili_items = json_decode( $item->TafsiliItems );
										$user_name     = '';
										if ( is_array( $tafsili_items ) && count( $tafsili_items ) > 0 ) {
											$user_name = isset( $tafsili_items[0]->TafsilItemName ) ? $tafsili_items[0]->TafsilItemName : '';
										}
										?>
										<div class="row">
											<?php
											if ( ! empty( $user_name ) ) {
												?>
												<div class="col-auto">
													<h5 class="pr-2 pb-2 pt-0 pl-0 m-0">نام شخص
														: <?php echo esc_html( $user_name ); ?></h5>
												</div>
												<?php
											}
											?>
											<div class="col-auto"><h5 class="pr-2 pb-2 pt-0 pl-0 m-0">کاربر
													: <?php echo $item->UserName ?></h5></div>
										</div>

										<table id="mobile-table" class="table">
											<thead>
											<tr>
												<th scope="col">نام کالا</th>
												<th scope="col">تعداد</th>
												<th scope="col">واحد</th>
												<th scope="col">هر قلم</th>
												<th scope="col">تخفیف</th>
												<th scope="col">قیمت کل</th>
											</tr>
											</thead>
											<tbody>
											<?php
											if ( is_array( $tafsili_items ) && count( $tafsili_items ) > 0 ) {
												$all_price    = 0;
												$all_discount = 0;
												$all_qty      = 0;

												foreach ( $tafsili_items as $tafsili_item ) {
													$price = isset( $tafsili_item->Credit ) ? $tafsili_item->Credit : $tafsili_item->Debit . '(-)';
													?>
													<tr>
														<td data-title="نام کالا"><?php echo $tafsili_item->DescForDisplay ?></td>
														<td data-title="تعداد"><?php echo isset( $tafsili_item->QT ) ? $tafsili_item->QT : ''; ?></td>
														<td data-title="واحد"><?php echo $tafsili_item->vahedForDisplay ?></td>
														<td data-title="هر قلم"><?php echo esc_html( $price ); ?></td>
														<td data-title="تخفیف"><?php echo $tafsili_item->DiscountForDisplay ?></td>
														<td data-title="قیمت کل"><?php echo $tafsili_item->RowSumForDisplay ?></td>
													</tr>
													<?php
//													$all_qty = $all_qty + isset($tafsili_item->QT) ? $tafsili_item->QT : 0;
													$all_discount = $all_discount + isset( $tafsili_item->Discount ) ? $tafsili_item->Discount : 0;
												}
											} else {
												?>
												<tr>
													<th colspan="6">فاکتوری موجود نیست.</th>
												</tr>
												<?php
											}
											?>
											</tbody>
											<?php
											if ( is_array( $tafsili_items ) && count( $tafsili_items ) > 0 ) {
												?>
												<tfoot>
												<tr>
													<td colspan="4"></td>
													<td data-title="مجموع تخفیف"><?php echo $all_discount ?></td>
													<td data-title="مجموع فاکتور"><?php echo number_format( $item->Credit ? $item->Credit : $item->Debit ) ?></td>
												</tr>
												</tfoot>
												<?php
											}
											?>
										</table>
									</div><!--
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                    <button type="button" class="btn btn-primary">Save changes</button>
                                </div>-->
								</div>
							</div>
						</div>
						<?php
					}
				}
			}
			?>
		</div>
		<?php
	}

	public function showTransactions() {
		$user_id   = get_current_user_id();
		$user_guid = get_user_meta( $user_id, 'guid', true ); /*'4469089E-F3CB-42E4-8D37-12C6EB90800A'*/

		if ( ! $user_guid ) {
			?>
			<div class="alert alert-warning text-center" role="alert">
				حساب کاربری شما در سیستم حسابداری ما تعریف نشده است. لطفا با پشتیبانی سایت تماس بگیرید. (شناسه
				کاربر: <?php echo $user_id; ?>)
			</div>
			<?php
			return;
		}
		$current_page = 1;
		$per_page     = absint( $this->atts['per_page'] );
		$per_page     = min( 100, max( 1, $per_page ) );
		$username     = SettingAPI::get( 'api_user' );
		$password     = SettingAPI::getSecret( 'api_pass' );

		$maybe_page_number = (int) ( $_REQUEST['novin_transactions_page'] ?? null );
		if ( $maybe_page_number > 0 ) {
			$current_page = $maybe_page_number;
		}

		$api_link = SettingAPI::get( 'api_url', 'https://novinrank.ir/' ) . 'Wc/GardeshTafsil/getall';

		$result = wp_remote_post( $api_link, array(
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
				'Content-Type'  => 'application/json',
			),
//			'data_format' => 'body',
			'body'      => wp_json_encode( [
				'TafsilGuid'  => $user_guid,
				'Date1'       => '',
				'Date2'       => '',
				'Per_Page'    => $per_page,
				'Page_Number' => $current_page
			] ),
		) );
		if ( $result instanceof \WP_Error ) {
			//var_dump($username,$api_link,$result);
			?>
			<div class="alert alert-warning text-center" role="alert">
				.خطایی در دریافت اطلاعات رخ داد.
			</div>
			<?php
			return;
		} elseif ( $result['response']['code'] !== 200 or empty( $result['body'] ) ) {
			?>
			<div class="alert alert-warning text-center" role="alert">
				<?php
				$body = json_decode( $result['body'], true );
				echo $body ? $body['message'] : 'خطای ناشناخته رخ داد!' ?>
			</div>
			<?php
			return;
		} else {
			$modals  = '';
			$headers = $result['headers']->getAll();
			?>
			<div class="novincommerce_transactions">
				<table>
					<caption>تراکنش‌های کاربر</caption>
					<thead>
					<tr>
						<th scope="col">تاریخ</th>
						<th scope="col">شرح سند</th>
						<th scope="col">بستانکار</th>
						<th scope="col">بدهکار</th>
						<th scope="col">مانده حساب</th>
						<th scope="col">عملیات</th>
					</tr>
					</thead>
					<tbody>
					<?php
					$decoded_result = json_decode( $result['body'] );

					$total_page = (int) $headers['total_pages'];
					unset( $result, $api_link, $username );
					//				$transactions = $decoded_result->Items;
					$transactions = $decoded_result;
					//var_dump($transactions);
					if ( is_array( $transactions ) && count( $transactions ) > 0 ) {
						foreach ( $transactions as $transaction ) {
//						$item = $transaction->Item;
							$item = $transaction;
							?>
							<tr>
								<td data-label="تاریخ"><?php echo esc_html( $item->dateSh ?? '' ); ?></td>
								<td data-label="شرح سند"><?php echo esc_html( $item->sanadDesc ?? '-' ); ?></td>
								<td data-label="بستانکار"><?php echo esc_html( $item->creditForDisplay ?? '' ); ?></td>
								<td data-label="بدهکار"><?php echo esc_html( $item->debitForDisplay ?? '' ); ?></td>
								<td data-label="مانده حساب"><?php echo esc_html( $item->remForDisplay ?? '' ); ?></td>
								<td data-label="عملیات">
									<button class="open-modal modal-btn"
											data-target="tafsili-<?php echo esc_attr( $item->asGuid ?? '' ); ?>">
										مشاهده کامل
									</button>
								</td>
							</tr>
							<?php
							//create tafsili modal
							$modals .= $this->createTafsiliModal( $transaction );

						}
					} else {
						?>
						<tr>
							<th colspan="6">تراکنشی وجود ندارد</th>
						</tr>
						<?php
					}
					?>
					</tbody>
				</table>

				<!-- Start Pagination -->
				<div class="pagination-wrapper">
					<div class="pagination">
						<a href="<?php echo $this->getPageUrl( $current_page - 1 ) ?>"
						   class="<?php echo $current_page === 1 ? 'disabled' : ''; ?>">&raquo;</a>
						<?php
						//pagination
						foreach ( $this->createPageArray( $current_page, $total_page ) as $page_number ) {
							if ( $page_number === '…' ) {
								?>
								<a href="#" class="disabled"><?php echo $page_number ?></a>
								<?php
							} else {
								?>
								<a href="<?php echo $this->getPageUrl( $page_number ) ?>"
								   class="<?php echo $current_page === $page_number ? 'active disabled' : ''; ?>"><?php echo $page_number ?></a>
								<?php
							}
						}
						?>
						<a href="<?php echo $current_page === $total_page ? '#' : $this->getPageUrl( $current_page + 1 ) ?>"
						   class="<?php echo $current_page === $total_page ? 'disabled' : ''; ?>">&laquo;</a>
					</div>
				</div>

				<!-- End Pagination -->
				<?php
				//echo modals
				echo $modals . '<div class="modal-fader"></div>';
				?>
			</div>
			<?php
		}
	}

	private function createTafsiliModal( $transaction ) {
		ob_start();
		?>
		<!-- Tafsili Modal -->
		<div id="tafsili-<?php echo esc_attr( $transaction->asGuid ?? '' ); ?>" class="modal-window">
			<?php
			$tafsili_items = $transaction->tafsiliItems;
			$user_name     = '';
			if ( is_array( $tafsili_items ) && count( $tafsili_items ) > 0 ) {
				$user_name = $tafsili_items[0]->tafsilItemName ?? '';
			}
			?>
			<div class="row">
				<?php
				if ( ! empty( $user_name ) ) {
					?>
					<div class="col-auto">
						<h5 class="pr-2 pb-2 pt-0 pl-0 m-0">نام شخص
							: <?php echo esc_html( $user_name ); ?></h5>
					</div>
					<?php
				}
				?>
				<div class="col-auto"><h5 class="pr-2 pb-2 pt-0 pl-0 m-0">کاربر
						: <?php echo esc_html( $transaction->userName ?? '' ); ?></h5></div>
			</div>
			<table>
				<thead>
				<tr>
					<th scope="col">نام کالا</th>
					<th scope="col">تعداد</th>
					<th scope="col">واحد</th>
					<th scope="col">هر قلم</th>
					<th scope="col">تخفیف</th>
					<th scope="col">قیمت کل</th>
				</tr>
				</thead>
				<tbody>
				<?php
				if ( is_array( $tafsili_items ) && count( $tafsili_items ) > 0 ) {
					$all_price    = 0;
					$all_discount = 0;
					$all_qty      = 0;

					foreach ( $tafsili_items as $tafsili_item ) {
						$price = $tafsili_item->credit ?? $tafsili_item->debit . '(-)';
						?>
						<tr>
							<td data-label="نام کالا"><?php echo esc_html( $tafsili_item->descForDisplay ?? '' ); ?></td>
							<td data-label="تعداد"><?php echo esc_html( $tafsili_item->qt ?? '' ); ?></td>
							<td data-label="واحد"><?php echo esc_html( empty( $tafsili_item->vahedForDisplay ) ? '-' : $tafsili_item->vahedForDisplay ); ?></td>
							<td data-label="هر قلم"><?php echo esc_html( $price ); ?></td>
							<td data-label="تخفیف"><?php echo esc_html( $tafsili_item->discountForDisplay ?? '' ); ?></td>
							<td data-label="قیمت کل"><?php echo esc_html( $tafsili_item->rowSumForDisplay ?? '' ); ?></td>
						</tr>
						<?php
//													$all_qty = $all_qty + isset($tafsili_item->QT) ? $tafsili_item->QT : 0;
						$all_discount = $all_discount + ( $tafsili_item->discount ?? 0 );
					}
				} else {
					?>
					<tr>
						<th colspan="6">فاکتوری موجود نیست.</th>
					</tr>
					<?php
				}
				?>
				</tbody>
				<?php
				if ( is_array( $tafsili_items ) && count( $tafsili_items ) > 0 ) {
					?>
					<tfoot>
					<tr>
						<td colspan="4"></td>
						<td data-title="مجموع تخفیف"><?php echo $all_discount ?></td>
						<td data-title="مجموع فاکتور"><?php echo number_format( $transaction->credit ? $transaction->credit : $transaction->debit ) ?></td>
					</tr>
					</tfoot>
					<?php
				}
				?>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	public function getPageUrl( $page_number ) {
		if ( $page_number <= 0 ) {
			return '#';
		}

		return esc_url( add_query_arg( 'novin_transactions_page', absint( $page_number ) ) );
	}

	public function createPageArray( $current_page, $total_page ) {
		$max_block_group = 3; // this must be odd if 3 than max_block = 9
		if ( $total_page < $max_block_group + 3 ) {
			return range( 1, $total_page );
		}
		$current_page           = $current_page > $total_page ? $total_page : $current_page;
		$page_number_array      = [];
		$add_mines_current_page = ( $max_block_group - 1 ) / 2;
		if ( $current_page <= 3 ) {
			$page_number_array   = array_merge( $page_number_array, range( 1, $max_block_group ) );
			$page_number_array[] = '…';
			$page_number_array[] = $total_page;
		} elseif ( $current_page < $total_page - 2 ) {
			$page_number_array[] = 1;
			$page_number_array[] = '…';
			$page_number_array   = array_merge( $page_number_array, range( $current_page - $add_mines_current_page, $current_page + $add_mines_current_page ) );
			$page_number_array[] = '…';
			$page_number_array[] = $total_page;
		} elseif ( $current_page >= $total_page - 2 ) {
			$page_number_array[] = 1;
			$page_number_array[] = '…';
			$page_number_array   = array_merge( $page_number_array, range( $total_page - $max_block_group + 1, $total_page ) );
		}

		return $page_number_array;
	}
}
