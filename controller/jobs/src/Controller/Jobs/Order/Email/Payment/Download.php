<?php

/**
 * @copyright Copyright (c) 12 Paw Paperie, 2015
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @package Controller
 * @subpackage Order
 */


/**
 * Order payment e-mail job controller.
 *
 * @package Controller
 * @subpackage Order
 */
class Controller_Jobs_Order_Email_Payment_Download
	extends Controller_Jobs_Abstract
	implements Controller_Jobs_Interface
{
	/**
	 * Returns the localized name of the job.
	 *
	 * @return string Name of the job
	 */
	public function getName()
	{
		return $this->_getContext()->getI18n()->dt( 'controller/jobs', 'Order payment related e-mails' );
	}


	/**
	 * Returns the localized description of the job.
	 *
	 * @return string Description of the job
	 */
	public function getDescription()
	{
		return $this->_getContext()->getI18n()->dt( 'controller/jobs', 'Sends order confirmation or payment status update e-mails' );
	}


	/**
	 * Executes the job.
	 *
	 * @throws Controller_Jobs_Exception If an error occurs
	 */
	public function run()
	{
		$arcavias = $this->_getArcavias();
		$context = $this->_getContext();
		$config = $context->getConfig();
		$mailer = $context->getMail();
		$view = $context->getView();

		$templatePaths = $arcavias->getCustomPaths( 'client/html' );

		$helper = new MW_View_Helper_Config_Default( $view, $config );
		$view->addHelper( 'config', $helper );

		$client = Client_Html_Email_Payment_Factory::createClient( $context, $templatePaths );

		$orderManager = MShop_Order_Manager_Factory::createManager( $context );
		$orderStatusManager = $orderManager->getSubManager( 'status' );
		$orderBaseManager = $orderManager->getSubManager( 'base' );

		/** controller/jobs/order/email/payment/download/limit-days
		 * Only send payment e-mails of orders that were created in the past within the configured number of days
		 *
		 * The payment e-mails are normally send immediately after the payment
		 * status has changed. This option prevents e-mails for old order from
		 * being send in case anything went wrong or an update failed to avoid
		 * confusion of customers.
		 *
		 * @param integer Number of days
		 * @since 2014.03
		 * @category User
		 * @category Developer
		 * @see controller/jobs/order/email/delivery/download/limit-days
		 * @see controller/jobs/service/delivery/process/limit-days
		 */
		$limit = $config->get( 'controller/jobs/order/email/payment/download/limit-days', 30 );
		$limitDate = date( 'Y-m-d H:i:s', time() - $limit * 86400 );

		$default = array(
			MShop_Order_Item_Abstract::PAY_RECEIVED,
		);

		/** controller/jobs/order/email/payment/download/status
		 * Only send order payment notification e-mails for these payment status values
		 *
		 * Notification e-mail about payment status changes can be sent for these
		 * status values:
		 * * 0: deleted
		 * * 1: canceled
		 * * 2: refused
		 * * 3: refund
		 * * 4: pending
		 * * 5: authorized
		 * * 6: received
		 *
		 * User-defined status values are possible but should be in the private
		 * block of values between 30000 and 32767.
		 *
		 * @param integer Payment status constant
		 * @since 2014.03
		 * @category User
		 * @category Developer
		 * @see controller/jobs/order/email/delivery/download/status
		 * @see controller/jobs/order/email/payment/download/limit-days
		 */
		foreach( (array) $config->get( 'controller/jobs/order/email/payment/download/status', $default ) as $status )
		{
			$orderSearch = $orderManager->createSearch();

			$param = array( MShop_Order_Item_Status_Abstract::EMAIL_PAYMENT, $status );
			$orderFunc = $orderSearch->createFunction( 'order.containsStatus', $param );

			$expr = array(
				$orderSearch->compare( '>=', 'order.mtime', $limitDate ),
				$orderSearch->compare( '==', 'order.statuspayment', $status ),
				$orderSearch->compare( '==', $orderFunc, 0 ),
			);
			$orderSearch->setConditions( $orderSearch->combine( '&&', $expr ) );

			$start = 0;

			do
			{
				$items = $orderManager->searchItems( $orderSearch );

				foreach( $items as $id => $item )
				{
					try
					{
						$orderBaseItem = $orderBaseManager->load( $item->getBaseId() );
                        $downloadAttrList = $this->loadDownloadAttrList( $orderBaseItem, $context );
						$view->extOrderBaseItem = $orderBaseItem;
						$view->extOrderItem = $item;
                        $view->extDownloadList = $downloadAttrList;

						$addr = $orderBaseItem->getAddress( MShop_Order_Item_Base_Address_Abstract::TYPE_PAYMENT );

						$helper = new MW_View_Helper_Translate_Default( $view, $context->getI18n( $addr->getLanguageId() ) );
						$view->addHelper( 'translate', $helper );

						$message = $mailer->createMessage();
						$helper = new MW_View_Helper_Mail_Default( $view, $message );
						$view->addHelper( 'mail', $helper );

						$client->setView( $view );
						$client->getHeader();
						$client->getBody();

						$mailer->send( $message );

						$statusItem = $orderStatusManager->createItem();
						$statusItem->setParentId( $id );
						$statusItem->setType( MShop_Order_Item_Status_Abstract::EMAIL_PAYMENT );
						$statusItem->setValue( $status );

						$orderStatusManager->saveItem( $statusItem );
					}
					catch( Exception $e )
					{
						$str = 'Error while trying to send payment e-mail for order ID "%1$s" and status "%2$s": %3$s';
						$msg = sprintf( $str, $item->getId(), $item->getPaymentStatus(), $e->getMessage() );
						$context->getLogger()->log( $msg );
					}
				}

				$count = count( $items );
				$start += $count;
				$orderSearch->setSlice( $start );
			}
			while( $count >= $orderSearch->getSliceSize() );
		}
	}
    
    protected function loadDownloadAttrList( MShop_Order_Item_Base_Interface $order, $context )
    {
        $downloads = array();
        
        $downloadManager = MShop_Factory::createManager( $context, 'download' );
        foreach( $order->getProducts() as $product )
        {
            $search = $downloadManager->createSearch( true );
            $expr = array(
                $search->compare( '==', 'download.prodid', $product->getId() ),
                $search->compare( '==', 'download.orderid', $order->getId() ),
                $search->getConditions(),
            );
            $search->setConditions( $search->combine( '&&', $expr ) );
            
            $dlList = $downloadManager->searchItems( $search );
            if( count( $dlList ) > 0 )
            {
                $download = reset( $dlList );
                $downloads[ $product->getId() ] = $download->getFileId();
            }
        }
        return $downloads;
    }
    
}
