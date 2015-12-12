<?php /* game_logic.php */ if (!isset($VERSION)) die('Include only.');
/******************************************************************************
* REMOTE CHESS - GAME LOGIC and MAIN CONTROL
******************************************************************************/

/**
 * new_board()
 * Make an 8 x 8 array. //...Is there a better way?
 */
function new_board()
{
	$ret = Array();

	for( $row = 0 ; $row < 8 ; $row++ ) {
		$ret[$row] = Array();
		for( $col = 0 ; $col < 8 ; $col++ ) {
			$ret[$row][$col] = '';
		}
	}

	return $ret;
}


/**
 * apply_move() - transfers a piece to another field
 */
function apply_move( $board_array,  $f_row, $f_col,  $t_row, $t_col )
{
	$piece = $board_array[$f_row][$f_col];
	$board_array[$f_row][$f_col] = '';

	// Turn not yet moved pieces into already moved ones
	if ($piece == 'S') $piece = 'R';   // White rooks
	if ($piece == 's') $piece = 'r';   // Black rook
	if ($piece == 'L') $piece = 'K';   // White king
	if ($piece == 'l') $piece = 'k';   // Black king

	$board_array[$t_row][$t_col] = $piece;

	return $board_array;

} // apply_move


/**
 * select_piece() - Creates  $possible_moves
 * The returned data will be used when building markup with clickable pieces
 */
function select_piece( $board_array, $current_player, $field )
{
	$clickable = $selected = possible_move_list(
		$board_array,
		$current_player,
		$field
	);

	return Array( $clickable, $selected );
}


/******************************************************************************
* HISTORY
******************************************************************************/

/**
 * decode_history()
 */
function decode_history( $base_array, $history )
{
	$ret = $base_array;

	$length = strlen( $history );
	for( $i = 0 ; $i < $length ; $i += 2 ) {
		$from_code = substr( $history, $i, 1 );
		$to_code   = substr( $history, $i+1, 1 );

		$from_field = decode_field( $from_code );
		$to_field   = decode_field( $to_code );

		list( $f_row, $f_col ) = field_to_rowcol( $from_field );
		list( $t_row, $t_col ) = field_to_rowcol( $to_field );

		$ret = apply_move( $ret,  $f_row, $f_col,  $t_row, $t_col );
	}

	return $ret;

} // decode_history


/**
 * history_markup()
 */
function history_markup( $board, $history, $name_white, $name_black )
{
	global $PIECE_NAMES;

	$ret = "<pre class=\"history\">\n";
	$ret .= "<b>$name_white</b> vs. <b>$name_black</b>\n";

	$length = strlen( $history );
	for( $i = 0 ; $i < $length ; $i += 2 ) {
		$from_code = substr( $history, $i, 1 );
		$to_code   = substr( $history, $i+1, 1 );

		$from_field = decode_field( $from_code );
		$to_field   = decode_field( $to_code );

		list( $f_row, $f_col ) = field_to_rowcol( $from_field );
		list( $t_row, $t_col ) = field_to_rowcol( $to_field );

		if ($i % 4 == 0) {
			$nr = ($i/4 + 1) . ': ';
			while (false && strlen($nr) < 5) {
				$nr = ' ' . $nr;
			}
			$ret .= $nr;
		}

		$piece = $board[$f_row][$f_col];
		$ret .= piece_glyph( $piece );
		$ret .= strtolower( $from_field );

		if ($board[$t_row][$t_col] != '') {
			$ret .= '<span>&#215;</span>';
		} else {
			$ret .= '<span>&ndash;</span>';
		}

		$ret .= strtolower( $to_field );

		if ($i % 4 == 0) {
			$ret .= ', ';
		} else {
			$ret .= "\n";
		}

		$board = apply_move( $board,  $f_row, $f_col,  $t_row, $t_col );
	}

	if (substr($ret, -1, 1) != "\n") {
		$ret .= "\n";
	}

	if (HISTORY_PROMPT) {
		//...
	}

	return $ret . "</pre>\n";

} // history_markup


/******************************************************************************
* MAIN CONTROL
******************************************************************************/

function main_control()
{
	// These globals used as output to HTML template only

	global $current_player, $history;
	global $heading, $name_white, $name_black;
	global $show_command_form, $promotion_popup, $flip_board;
	global $preset_from_value, $preset_to_value, $id_focus;
	global $chess_board_markup, $history_markup;
	global $board_encoded;
	global $href_this, $href_test, $href_player, $href_flip;


	// Initialize a bit

	$heading = '';
	$show_command_form = true;
	$promotion_popup = false;


	// Retreive GET data
	$flip_board = isset( $_GET[GET_FLIP_BOARD] );
	$history = get_parameter( GET_HISTORY );
	$name_white = get_parameter( GET_WHITE, DEFAULT_NAME_WHITE );
	$name_black = get_parameter( GET_BLACK, DEFAULT_NAME_BLACK );

	// &player set to anything but "white" implies "black" was given
	if (get_parameter(GET_PLAYER, GET_WHITE) != GET_WHITE) {
		$current_player = BLACKS_MOVE;
	} else {
		$current_player = WHITES_MOVE;
	}

	// Load base positions of pieces
	$base_array = decode_board(
		get_parameter( GET_BASE_BOARD, INITIAL_BOARD_CODED )
	);


	// Trace history
	if (RECONSTRUCT_FROM_HISTORY) {
		$board_array = decode_history(
			$base_array,
			$history
		);
	} else {
		$board_array = $base_array;
	}


	// Validate FORM input ("from" and "to" must be field names)

	$cmd_from = strtoupper( get_parameter(GET_FROM) ); // Retreive commands
	$cmd_to   = strtoupper( get_parameter(GET_TO) );

	if (! valid_field_name( $cmd_from )) $cmd_from = '';
	if (! valid_field_name( $cmd_to   )) $cmd_to   = '';

	if ($cmd_from == $cmd_to) {   // Deselect a piece
		$cmd_from = $cmd_to = '';
	}
	if ($cmd_from == '') $cmd_to = '';    // Never allow only TO command


	// Execute given command

	$clickable = $selected = Array();
	$redirect_after_move = false;

	// Exec: Move
	if (($cmd_from != '') && ($cmd_to != '')) {

		list($f_row, $f_col) = field_to_rowcol( $cmd_from );
		list($t_row, $t_col) = field_to_rowcol( $cmd_to );

		list( $clickable, $selected ) = select_piece(
			$board_array,
			$current_player,
			$cmd_from
		);


		// Check if it is our//... piece (or a piece at all)

		if (! in_array( $cmd_from, $clickable )) {
			die( "Error: clickable[$f_row][$f_col] empty." );
		}

		if (! in_array( $cmd_to, $clickable )) {
			// Capturing a piece!
			echo "Capture! clickable = ";
			print_r( $clickable );
			die();
		}


		// En passant

		$en_passant = '';
		$piece = strtolower( $board_array[$f_row][$t_row] );
		if(($piece == 'P') && ($f_row == 1)
		|| ($piece == 'p') && ($f_row == 6)
		) {
			if (($t_row == 3) || ($t_row == 4)) {
				$en_passant = chr( ord('A') + $col );
			}
		}


		// Apply move to URL (old method)

		if (! RECONSTRUCT_FROM_HISTORY) {
			$board_array = apply_move(
				$board_array,
				$f_row, $f_col,
				$t_row, $t_col
			);
		}


		// New move applied, prepare for fresh move

		$clickable = $selected = Array();
		$cmd_from = $cmd_to = '';
		$current_player = ! $current_player;

		$history .= encode_move( $f_row, $f_col,  $t_row, $t_col );

		// We changed the board, but the user's browser still shows the
		// move command in its address bar. An HTTP redirect is used to
		// update that address, but the URL is not determined yet
		$redirect_after_move = true;
	}

	// Exec: Deselect
	if (($cmd_from == '') && ($cmd_to == '')) {

		$clickable = find_movable_pieces(
			$board_array,
			$current_player
		);

		$pieces_available = count( $clickable );
		if ($pieces_available == 0) {
			//... If still pieces left, no legal moves? Stalemate.
			$heading = "Checkmate!";
			$show_command_form = false;
		} else {
			debug_out( "\nPieces avail: $pieces_available" );
		}
	}

	// Exec: Select piece
	if (($cmd_from != '') && ($cmd_to == '')) {

		list( $clickable, $selected ) = select_piece(
			$board_array,
			$current_player,
			$cmd_from
		);

		$heading = 'Select target';
	}

	$preset_from_value = $cmd_from;
	$preset_to_value = $cmd_to;

	$id_focus = ($preset_from_value == '') ? 'idFrom' : 'idTo' ;


	// Generate links for main menu and board markup (pieces)

	if (RECONSTRUCT_FROM_HISTORY) {
		$board_encoded = encode_board( $base_array );
	} else {
		$board_encoded = encode_board( $board_array );
	}

	// Name parameter as code for who's player's term this is
	$p = ($current_player == WHITES_MOVE) ? GET_WHITE : GET_BLACK ;

	$href_this = update_href();   // get base link
	$href_this = update_href( $href_this, GET_FROM, $preset_from_value );
	$href_this = update_href( $href_this, GET_TO, $preset_to_value );
	$href_this = update_href( $href_this, GET_PLAYER, $p );
	$href_this = update_href( $href_this, GET_HISTORY, $history );
	$href_this = update_href( $href_this, GET_WHITE, $name_white );
	$href_this = update_href( $href_this, GET_BLACK, $name_black );
	$href_this = update_href( $href_this, GET_EN_PASSANT, $en_passant );

	$href_this = update_href( $href_this, GET_BASE_BOARD, $board_encoded );
	debug_out( "\nboard_encoded = $board_encoded" );

	if ($flip_board) {
		$href_this = update_href( $href_this, GET_FLIP_BOARD, '' );
		$href_flip = update_href( $href_this, GET_FLIP_BOARD, REMOVE_FROM_LINK );
	} else {
		$href_flip = update_href( $href_this, GET_FLIP_BOARD, '' );
	}

	if ($current_player == BLACKS_MOVE) {
		$href_player = update_href( $href_this, GET_PLAYER, GET_WHITE );
	} else {
		$href_player = update_href( $href_this, GET_PLAYER, GET_BLACK );
	}

	// Test link
	$href_test = update_href( TEST_LINK, '', '' );


	// HTTP redirect?

	if ($redirect_after_move) {
		// Game state has been updated. In case of an executed move,
		// the browser needs to reload the page with the updated URL:
		header( 'HTTP/1.0 303 Found') ;
		header( 'Location: ' . htmlspecialchars_decode($href_this) );
		die();
	}


	// Create HTML markup

	if (RECONSTRUCT_FROM_HISTORY) {
		$history_markup = history_markup(
			$base_array,
			$history,
			$name_white,
			$name_black
		);
	} else {
		$history_markup = history_markup_old(
			$board_array,
			$history,
			$name_white,
			$name_black
		);
	}

	// Promotion popup
	if ($promotion_popup) {
		$clickable = $selected = Array();
		$heading = 'Promote your pawn to:';
		//... Select that pawn
	}

	$chess_board_markup = chess_board_markup(
		$href_this,
		$board_array,
		$clickable,
		$selected,
		$current_player,
		$flip_board
	);


	// If no heading was set above, say who's next

	if ($heading == '') {
		$heading = ($current_player) ? $name_white : $name_black ;
		$heading = ucfirst($heading) . "'";
		if (substr($heading, -2, 1) != 's') $heading .= 's';
		$heading .= ' move';
	}


	// Debug

	debug_out( "\nHistory: " );
	debug_out( ((RECONSTRUCT_FROM_HISTORY) ? "on" : "off") );
	debug_out( "\n\n" );

	$t = str_replace( '&amp;', '&', $href_this );
	$t = str_replace( ' ', '+', $t );
	for( $i = 0 ; $i < strlen($t) ; $i += 32 ) {
		debug_out( substr($t, $i, 32) . "\n" );
	}

} // main_conrol


# EOF ?>