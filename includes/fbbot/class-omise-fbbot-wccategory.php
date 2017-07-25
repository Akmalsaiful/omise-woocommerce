<?php
defined( 'ABSPATH' ) or die( "No direct script access allowed." );

if (  class_exists( 'Omise_FBBot_WCCategory') ) {
  return;
}

class Omise_FBBot_WCCategory {
	static public function create( $wc_category ) {
		return new Omise_FBBot_WCCategory( $wc_category );
	}

	public function __construct( $wc_category ) {
		$this->id = $wc_category->term_id;
		$this->name = $wc_category->name;
		$this->description = $wc_category->description;
		$this->slug = $wc_category->slug;
		$this->permalink = get_term_link( $wc_category->slug, 'product_cat' );

		$thumbnail_id = get_woocommerce_term_meta( $wc_category->term_id, 'thumbnail_id', true );
		$this->thumbnail_img = wp_get_attachment_url( $thumbnail_id );
	}
}