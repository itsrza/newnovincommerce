<?php

/**
 * Canonical fixture payloads for the Product Data Model QA suite.
 * These are source contracts, not production records or credentials.
 */
return array(
	'NP567' => array(
		'Guid' => 'fixture-np567',
		'Code' => 'NP567',
		'Name' => 'NP567 sample',
		'Mojodi' => 84,
		'PrdAnbarRelation' => array(
			array( 'AnbarGuid' => 'warehouse-main', 'Amount' => 24 ),
			array( 'AnbarGuid' => 'warehouse-secondary', 'Amount' => 6 ),
		),
		'V2Qt' => null,
		'V3Qt' => null,
		'PriceRoleList' => array(),
	),
	'Saffron' => array(
		'Guid' => 'fixture-saffron',
		'Code' => 'SAFFRON',
		'Name' => 'Saffron',
		'Mojodi' => 1000,
		'PrdAnbarRelation' => array( array( 'AnbarGuid' => 'warehouse-main', 'Amount' => 1000 ) ),
		'V2Qt' => null,
		'V3Qt' => null,
		'PriceRoleList' => array(),
	),
	'iPhone' => array(
		'Guid' => 'fixture-iphone-parent',
		'Name' => 'iPhone variable parent',
		'VahedName' => 'عدد',
		'V2Qt' => 6,
		'V2Guid' => 'unit-box',
		'V3Qt' => 24,
		'V3Guid' => 'unit-case',
		'AttributeRelations' => array(
			array( 'Guid' => 'unit-box', 'Name' => 'جعبه' ),
			array( 'Guid' => 'unit-case', 'Name' => 'کارتن' ),
		),
		'PrdAnbarRelation' => array( array( 'AnbarGuid' => 'warehouse-main', 'Amount' => 24 ) ),
	),
	'Shirt' => array(
		'Guid' => 'fixture-shirt-parent',
		'Name' => 'Shirt variable parent',
		'PrdAnbarRelation' => array( array( 'AnbarGuid' => 'warehouse-main', 'Amount' => 12 ) ),
		'V2Qt' => null,
		'V3Qt' => null,
	),
	'ExpiredDiscount' => array(
		'Guid' => 'fixture-expired-discount',
		'DiscountPercent' => 20,
		'DiscountStartDate' => '2020-01-01 00:00:00',
		'DiscountEndDate' => '2020-01-02 00:00:00',
	),
	'MissingSchedule' => array(
		'Guid' => 'fixture-missing-schedule',
		'DiscountPercent' => 20,
	),
	'EightPriceLevels' => array(
		'Guid' => 'fixture-eight-levels',
		'Sell1' => 101,
		'Sell2' => 102,
		'Sell3' => 103,
		'Sell4' => 104,
		'Sell5' => 105,
		'Sell6' => 106,
		'Sell7' => 107,
		'Sell8' => 108,
		'PriceRoleList' => array(
			array( 'WordPressRoleName' => 'Editor', 'Price' => 101 ),
		),
	),
	'Composite' => array(
		'Guid' => 'fixture-composite',
		'Kind' => 7,
		'Composite' => true,
		'TolidFormula' => 'formula-1',
		'ProductionCapacity' => 40,
		'V2Qt' => null,
		'V3Qt' => null,
	),
);
