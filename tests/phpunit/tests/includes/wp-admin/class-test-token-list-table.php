<?php

use IndieAuth\WP_Admin\Token_List_Table;

class Test_Token_List_Table extends WP_UnitTestCase {

	// The scope comes from the client and must not be printed as HTML.
	public function test_scope_column_is_escaped() {
		set_current_screen( 'users' );
		$table = new Token_List_Table();
		$this->assertSame(
			'create &lt;img src=x onerror=alert(1)&gt;',
			$table->column_default( array( 'scope' => 'create <img src=x onerror=alert(1)>' ), 'scope' )
		);
	}
}
