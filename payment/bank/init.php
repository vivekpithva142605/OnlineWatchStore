<?php
ob_start();
session_start();

include("../../admin/inc/config.php");
include("../../admin/inc/functions.php");

// Load language variables.
$i = 1;
$statement = $pdo->prepare("SELECT * FROM tbl_language");
$statement->execute();
$result = $statement->fetchAll(PDO::FETCH_ASSOC);

foreach ($result as $row) {
    define('LANG_VALUE_' . $i, $row['lang_value']);
    $i++;
}

if (!isset($_REQUEST['msg'])) {

    // A bank transaction reference is required.
    if (empty($_POST['transaction_info'])) {
        header('Location: ../../checkout.php');
        exit;
    }

    // Make sure the customer is logged in.
    if (
        !isset($_SESSION['customer']) ||
        !isset($_SESSION['customer']['cust_id']) ||
        !isset($_SESSION['customer']['cust_name']) ||
        !isset($_SESSION['customer']['cust_email'])
    ) {
        header('Location: ../../checkout.php');
        exit;
    }

    // Make sure all required cart sessions exist and contain arrays.
    $required_cart_sessions = array(
        'cart_p_id',
        'cart_p_name',
        'cart_size_name',
        'cart_color_name',
        'cart_p_qty',
        'cart_p_current_price'
    );

    foreach ($required_cart_sessions as $session_key) {
        if (
            !isset($_SESSION[$session_key]) ||
            !is_array($_SESSION[$session_key]) ||
            count($_SESSION[$session_key]) === 0
        ) {
            // Do not create a payment record when the cart is empty or missing.
            header('Location: ../../checkout.php');
            exit;
        }
    }

    // Use the product ID array as the reference and verify all cart arrays
    // have the same number of items.
    $cart_count = count($_SESSION['cart_p_id']);

    foreach ($required_cart_sessions as $session_key) {
        if (count($_SESSION[$session_key]) !== $cart_count) {
            header('Location: ../../checkout.php');
            exit;
        }
    }

    if (!isset($_POST['amount']) || !is_numeric($_POST['amount'])) {
        header('Location: ../../checkout.php');
        exit;
    }

    $payment_date = date('Y-m-d H:i:s');
    $payment_id = time();

    try {
        // Keep payment, order creation, and stock updates together.
        $pdo->beginTransaction();

        // Create payment record only after validating the cart.
        $statement = $pdo->prepare("
            INSERT INTO tbl_payment (
                customer_id,
                customer_name,
                customer_email,
                payment_date,
                txnid,
                paid_amount,
                card_number,
                card_cvv,
                card_month,
                card_year,
                bank_transaction_info,
                payment_method,
                payment_status,
                shipping_status,
                payment_id
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $statement->execute(array(
            $_SESSION['customer']['cust_id'],
            $_SESSION['customer']['cust_name'],
            $_SESSION['customer']['cust_email'],
            $payment_date,
            '',
            $_POST['amount'],
            '',
            '',
            '',
            '',
            trim($_POST['transaction_info']),
            'Bank Deposit',
            'Pending',
            'Pending',
            $payment_id
        ));

        // Insert each order item and update its product stock.
        $cart_product_ids = array_values($_SESSION['cart_p_id']);
        $cart_product_names = array_values($_SESSION['cart_p_name']);
        $cart_size_names = array_values($_SESSION['cart_size_name']);
        $cart_color_names = array_values($_SESSION['cart_color_name']);
        $cart_quantities = array_values($_SESSION['cart_p_qty']);
        $cart_prices = array_values($_SESSION['cart_p_current_price']);

        $insert_order = $pdo->prepare("
            INSERT INTO tbl_order (
                product_id,
                product_name,
                size,
                color,
                quantity,
                unit_price,
                payment_id
            ) VALUES (?,?,?,?,?,?,?)
        ");

        $get_stock = $pdo->prepare("
            SELECT p_qty
            FROM tbl_product
            WHERE p_id = ?
            FOR UPDATE
        ");

        $update_stock = $pdo->prepare("
            UPDATE tbl_product
            SET p_qty = ?
            WHERE p_id = ?
        ");

        for ($i = 0; $i < $cart_count; $i++) {
            $product_id = $cart_product_ids[$i];
            $quantity = (int) $cart_quantities[$i];

            if ($quantity <= 0) {
                throw new Exception('Invalid product quantity in cart.');
            }

            // Lock and check stock before creating the order item.
            $get_stock->execute(array($product_id));
            $product = $get_stock->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception('A product in your cart could not be found.');
            }

            $current_qty = (int) $product['p_qty'];

            if ($current_qty < $quantity) {
                throw new Exception('Not enough stock for ' . $cart_product_names[$i] . '.');
            }

            $insert_order->execute(array(
                $product_id,
                $cart_product_names[$i],
                $cart_size_names[$i],
                $cart_color_names[$i],
                $quantity,
                $cart_prices[$i],
                $payment_id
            ));

            $final_quantity = $current_qty - $quantity;
            $update_stock->execute(array($final_quantity, $product_id));
        }

        $pdo->commit();

        // Clear cart only after all database operations succeed.
        unset(
            $_SESSION['cart_p_id'],
            $_SESSION['cart_size_id'],
            $_SESSION['cart_size_name'],
            $_SESSION['cart_color_id'],
            $_SESSION['cart_color_name'],
            $_SESSION['cart_p_qty'],
            $_SESSION['cart_p_current_price'],
            $_SESSION['cart_p_name'],
            $_SESSION['cart_p_featured_photo']
        );

        header('Location: ../../payment_success.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Log the technical error; do not expose database details to customers.
        error_log('Bank payment initialization failed: ' . $e->getMessage());

        header('Location: ../../checkout.php');
        exit;
    }
}
?>
