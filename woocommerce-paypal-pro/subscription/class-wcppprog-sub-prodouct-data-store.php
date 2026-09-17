<?php

// Also Make sure WC_Product_Simple is loaded first
if ( ! class_exists( 'WC_Product_Data_Store_CPT' ) ) {
	return;
}

class WCPPROG_Subscription_Product_Data_Store_CPT extends WC_Product_Data_Store_CPT {

}