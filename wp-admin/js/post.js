/**
 * @file Contains all dynamic functionality needed on post and term pages.
 *
 * @output wp-admin/js/post.js
 */

 /* global ajaxurl, wpAjax, postboxes, pagenow, tinymce, alert, deleteUserSetting, ClipboardJS */
 /* global theList:true, theExtraList:true, getUserSetting, setUserSetting, commentReply, commentsBox */
 /* global WPSetThumbnailHTML, wptitlehint */

// Backward compatibility: prevent fatal errors.
window.makeSlugeditClickable = window.editPermalink = function(){};

// Make sure the wp object exists.
window.wp = window.wp || {};

( function( $ ) {
	var titleHasFocus = false,
		__ = wp.i18n.__;

	/**
	 * Control loading of comments on the post and term edit pages.
	 *
	 * @type {{st: number, get: commentsBox.get, load: commentsBox.load}}
	 *
	 * @namespace commentsBox
	 */
	window.commentsBox = {
		// Comment offset to use when fetching new comments.
		st : 0,

		/**
		 * Fetch comments using Ajax and display them in the box.
		 *
		 * @memberof commentsBox
		 *
		 * @param {number} total Total number of comments for this post.
		 * @param {number} num   Optional. Number of comments to fetch, defaults to 20.
		 * @return {boolean} Always returns false.
		 */
		get : function(total, num) {
			var st = this.st, data;
			if ( ! num )
				num = 20;

			this.st += num;
			this.total = total;
			$( '#commentsdiv .spinner' ).addClass( 'is-active' );

			data = {
				'action' : 'get-comments',
				'mode' : 'single',
				'_ajax_nonce' : $('#add_comment_nonce').val(),
				'p' : $('#post_ID').val(),
				'start' : st,
				'number' : num
			};

			$.post(
				ajaxurl,
				data,
				function(r) {
					r = wpAjax.parseAjaxResponse(r);
					$('#commentsdiv .widefat').show();
					$( '#commentsdiv .spinner' ).removeClass( 'is-active' );

					if ( 'object' == typeof r && r.responses[0] ) {
						$('#the-comment-list').append( r.responses[0].data );

						theList = theExtraList = null;
						$( 'a[className*=\':\']' ).off();

						// If the offset is over the total number of comments we cannot fetch any more, so hide the button.
						if ( commentsBox.st > commentsBox.total )
							$('#show-comments').hide();
						else
							$('#show-comments').show().children('a').text( __( 'Show more comments' ) );

						return;
					} else if ( 1 == r ) {
						$('#show-comments').text( __( 'No more comments found.' ) );
						return;
					}

					$('#the-comment-list').append('<tr><td colspan="2">'+wpAjax.broken+'</td></tr>');
				}
			);

			return false;
		},

		/**
		 * Load the next batch of comments.
		 *
		 * @memberof commentsBox
		 *
		 * @param {number} total Total number of comments to load.
		 */
		load: function(total){
			this.st = jQuery('#the-comment-list tr.comment:visible').length;
			this.get(total);
		}
	};

	/**
	 * Overwrite the content of the Featured Image postbox
	 *
	 * @param {string} html New HTML to be displayed in the content area of the postbox.
	 *
	 * @global
	 */
	window.WPSetThumbnailHTML = function(html){
		$('.inside', '#postimagediv').html(html);
	};

	/**
	 * Set the Image ID of the Featured Image
	 *
	 * @param {number} id The post_id of the image to use as Featured Image.
	 *
	 * @global
	 */
	window.WPSetThumbnailID = function(id){
		var field = $('input[value="_thumbnail_id"]', '#list-table');
		if ( field.length > 0 ) {
			$('#meta\\[' + field.attr('id').match(/[0-9]+/) + '\\]\\[value\\]').text(id);
		}
	};

	/**
	 * Remove the Featured Image
	 *
	 * @param {string} nonce Nonce to use in the request.
	 *
	 * @global
	 */
	window.WPRemoveThumbnail = function(nonce){
		$.post(
			ajaxurl, {
				action: 'set-post-thumbnail',
				post_id: $( '#post_ID' ).val(),
				thumbnail_id: -1,
				_ajax_nonce: nonce,
				cookie: encodeURIComponent( document.cookie )
			},
			/**
			 * Handle server response
			 *
			 * @param {string} str Response, will be '0' when an error occurred otherwise contains link to add Featured Image.
			 */
			function(str){
				if ( str == '0' ) {
					alert( __( 'Could not set that as the thumbnail image. Try a different attachment.' ) );
				} else {
					WPSetThumbnailHTML(str);
				}
			}
		);
	};

	/**
	 * Heartbeat locks.
	 *
	 * Used to lock editing of an object by only one user at a time.
	 *
	 * When the user does not send a heartbeat in a heartbeat-time
	 * the user is no longer editing and another user can start editing.
	 */
	$(document).on( 'heartbeat-send.refresh-lock', function( e, data ) {
		var lock = $('#active_post_lock').val(),
			post_id = $('#post_ID').val(),
			send = {};

		if ( ! post_id || ! $('#post-lock-dialog').length )
			return;

		send.post_id = post_id;

		if ( lock )
			send.lock = lock;

		data['wp-refresh-post-lock'] = send;

	}).on( 'heartbeat-tick.refresh-lock', function( e, data ) {
		// Post locks: update the lock string or show the dialog if somebody has taken over editing.
		var received, wrap, avatar;

		if ( data['wp-refresh-post-lock'] ) {
			received = data['wp-refresh-post-lock'];

			if ( received.lock_error ) {
				// Show "editing taken over" message.
				wrap = $('#post-lock-dialog');

				if ( wrap.length && ! wrap.is(':visible') ) {
					if ( wp.autosave ) {
						// Save the latest changes and disable.
						$(document).one( 'heartbeat-tick', function() {
							wp.autosave.server.suspend();
							wrap.removeClass('saving').addClass('saved');
							$(window).off( 'beforeunload.edit-post' );
						});

						wrap.addClass('saving');
						wp.autosave.server.triggerSave();
					}

					if ( received.lock_error.avatar_src ) {
						avatar = $( '<img />', {
							'class': 'avatar avatar-64 photo',
							width: 64,
							height: 64,
							alt: '',
							src: received.lock_error.avatar_src,
							srcset: received.lock_error.avatar_src_2x ?
								received.lock_error.avatar_src_2x + ' 2x' :
								undefined
						} );
						wrap.find('div.post-locked-avatar').empty().append( avatar );
					}

					wrap.show().find('.currently-editing').text( received.lock_error.text );
					wrap.find('.wp-tab-first').trigger( 'focus' );
				}
			} else if ( received.new_lock ) {
				$('#active_post_lock').val( received.new_lock );
			}
		}
	}).on( 'before-autosave.update-post-slug', function() {
		titleHasFocus = document.activeElement && document.activeElement.id === 'title';
	}).on( 'after-autosave.update-post-slug', function() {

		/*
		 * Create slug area only if not already there
		 * and the title field was not focused (user was not typing a title) when autosave ran.
		 */
		if ( ! $('#edit-slug-box > *').length && ! titleHasFocus ) {
			$.post( ajaxurl, {
					action: 'sample-permalink',
					post_id: $('#post_ID').val(),
					new_title: $('#title').val(),
					samplepermalinknonce: $('#samplepermalinknonce').val()
				},
				function( data ) {
					if ( data != '-1' ) {
						$('#edit-slug-box').html(data);
					}
				}
			);
		}
	});

}(jQuery));

/**
 * Heartbeat refresh nonces.
 */
(function($) {
	var check, timeout;

	/**
	 * Only allow to check for nonce refresh every 30 seconds.
	 */
	function schedule() {
		check = false;
		window.clearTimeout( timeout );
		timeout = window.setTimeout( function(){ check = true; }, 300000 );
	}

	$( function() {
		schedule();
	}).on( 'heartbeat-send.wp-refresh-nonces', function( e, data ) {
		var post_id,
			$authCheck = $('#wp-auth-check-wrap');

		if ( check || ( $authCheck.length && ! $authCheck.hasClass( 'hidden' ) ) ) {
			if ( ( post_id = $('#post_ID').val() ) && $('#_wpnonce').val() ) {
				data['wp-refresh-post-nonces'] = {
					post_id: post_id
				};
			}
		}
	}).on( 'heartbeat-tick.wp-refresh-nonces', function( e, data ) {
		var nonces = data['wp-refresh-post-nonces'];

		if ( nonces ) {
			schedule();

			if ( nonces.replace ) {
				$.each( nonces.replace, function( selector, value ) {
					$( '#' + selector ).val( value );
				});
			}

			if ( nonces.heartbeatNonce )
				window.heartbeatSettings.nonce = nonces.heartbeatNonce;
		}
	});
}(jQuery));

/**
 * All post and postbox controls and functionality.
 */
jQuery( function($) {
	var stamp, visibility, $submitButtons, updateVisibility, updateText,
		$textarea = $('#content'),
		$document = $(document),
		postId = $('#post_ID').val() || 0,
		$submitpost = $('#submitpost'),
		releaseLock = true,
		$postVisibilitySelect = $('#post-visibility-select'),
		$timestampdiv = $('#timestampdiv'),
		$postStatusSelect = $('#post-status-select'),
		isMac = window.navigator.platform ? window.navigator.platform.indexOf( 'Mac' ) !== -1 : false,
		copyAttachmentURLClipboard = new ClipboardJS( '.copy-attachment-url.edit-media' ),
		copyAttachmentURLSuccessTimeout,
		__ = wp.i18n.__, _x = wp.i18n._x;

	postboxes.add_postbox_toggles(pagenow);

	/*
	 * Clear the window name. Otherwise if this is a former preview window where the user navigated to edit another post,
	 * and the first post is still being edited, clicking Preview there will use this window to show the preview.
	 */
	window.name = '';

	// Post locks: contain focus inside the dialog. If the dialog is shown, focus the first item.
	$('#post-lock-dialog .notification-dialog').on( 'keydown', function(e) {
		// Don't do anything when [Tab] is pressed.
		if ( e.which != 9 )
			return;

		var target = $(e.target);

		// [Shift] + [Tab] on first tab cycles back to last tab.
		if ( target.hasClass('wp-tab-first') && e.shiftKey ) {
			$(this).find('.wp-tab-last').trigger( 'focus' );
			e.preventDefault();
		// [Tab] on last tab cycles back to first tab.
		} else if ( target.hasClass('wp-tab-last') && ! e.shiftKey ) {
			$(this).find('.wp-tab-first').trigger( 'focus' );
			e.preventDefault();
		}
	}).filter(':visible').find('.wp-tab-first').trigger( 'focus' );

	// Set the heartbeat interval to 15 seconds if post lock dialogs are enabled.
	if ( wp.heartbeat && $('#post-lock-dialog').length ) {
		wp.heartbeat.interval( 15 );
	}

	// The form is being submitted by the user.
	$submitButtons = $submitpost.find( ':submit, a.submitdelete, #post-preview' ).on( 'click.edit-post', function( event ) {
		var $button = $(this);

		if ( $button.hasClass('disabled') ) {
			event.preventDefault();
			return;
		}

		if ( $button.hasClass('submitdelete') || $button.is( '#post-preview' ) ) {
			return;
		}

		// The form submission can be blocked from JS or by using HTML 5.0 validation on some fields.
		// Run this only on an actual 'submit'.
		$('form#post').off( 'submit.edit-post' ).on( 'submit.edit-post', function( event ) {
			if ( event.isDefaultPrevented() ) {
				return;
			}

			// Stop auto save.
			if ( wp.autosave ) {
				wp.autosave.server.suspend();
			}

			if ( typeof commentReply !== 'undefined' ) {
				/*
				 * Warn the user they have an unsaved comment before submitting
				 * the post data for update.
				 */
				if ( ! commentReply.discardCommentChanges() ) {
					return false;
				}

				/*
				 * Close the comment edit/reply form if open to stop the form
				 * action from interfering with the post's form action.
				 */
				commentReply.close();
			}

			releaseLock = false;
			$(window).off( 'beforeunload.edit-post' );

			$submitButtons.addClass( 'disabled' );

			if ( $button.attr('id') === 'publish' ) {
				$submitpost.find( '#major-publishing-actions .spinner' ).addClass( 'is-active' );
			} else {
				$submitpost.find( '#minor-publishing .spinner' ).addClass( 'is-active' );
			}
		});
	});

	// Submit the form saving a draft or an autosave, and show a preview in a new tab.
	$('#post-preview').on( 'click.post-preview', function( event ) {
		var $this = $(this),
			$form = $('form#post'),
			$previewField = $('input#wp-preview'),
			target = $this.attr('target') || 'wp-preview',
			ua = navigator.userAgent.toLowerCase();

		event.preventDefault();

		if ( $this.hasClass('disabled') ) {
			return;
		}

		if ( wp.autosave ) {
			wp.autosave.server.tempBlockSave();
		}

		$previewField.val('dopreview');
		$form.attr( 'target', target ).trigger( 'submit' ).attr( 'target', '' );

		// Workaround for WebKit bug preventing a form submitting twice to the same action.
		// https://bugs.webkit.org/show_bug.cgi?id=28633
		if ( ua.indexOf('safari') !== -1 && ua.indexOf('chrome') === -1 ) {
			$form.attr( 'action', function( index, value ) {
				return value + '?t=' + ( new Date() ).getTime();
			});
		}

		$previewField.val('');
	});

	// This code is meant to allow tabbing from Title to Post content.
	$('#title').on( 'keydown.editor-focus', function( event ) {
		var editor;

		if ( event.keyCode === 9 && ! event.ctrlKey && ! event.altKey && ! event.shiftKey ) {
			editor = typeof tinymce != 'undefined' && tinymce.get('content');

			if ( editor && ! editor.isHidden() ) {
				editor.focus();
			} else if ( $textarea.length ) {
				$textarea.trigger( 'focus' );
			} else {
				return;
			}

			event.preventDefault();
		}
	});

	// Auto save new posts after a title is typed.
	if ( $( '#auto_draft' ).val() ) {
		$( '#title' ).on( 'blur', function() {
			var cancel;

			if ( ! this.value || $('#edit-slug-box > *').length ) {
				return;
			}

			// Cancel the auto save when the blur was triggered by the user submitting the form.
			$('form#post').one( 'submit', function() {
				cancel = true;
			});

			window.setTimeout( function() {
				if ( ! cancel && wp.autosave ) {
					wp.autosave.server.triggerSave();
				}
			}, 200 );
		});
	}

	$document.on( 'autosave-disable-buttons.edit-post', function() {
		$submitButtons.addClass( 'disabled' );
	}).on( 'autosave-enable-buttons.edit-post', function() {
		if ( ! wp.heartbeat || ! wp.heartbeat.hasConnectionError() ) {
			$submitButtons.removeClass( 'disabled' );
		}
	}).on( 'before-autosave.edit-post', function() {
		$( '.autosave-message' ).text( __( 'Saving Draft…' ) );
	}).on( 'after-autosave.edit-post', function( event, data ) {
		$( '.autosave-message' ).text( data.message );

		if ( $( document.body ).hasClass( 'post-new-php' ) ) {
			$( '.submitbox .submitdelete' ).show();
		}
	});

	/*
	 * When the user is trying to load another page, or reloads current page
	 * show a confirmation dialog when there are unsaved changes.
	 */
	$( window ).on( 'beforeunload.edit-post', function( event ) {
		var editor  = window.tinymce && window.tinymce.get( 'content' );
		var changed = false;

		if ( wp.autosave ) {
			changed = wp.autosave.server.postChanged();
		} else if ( editor ) {
			changed = ( ! editor.isHidden() && editor.isDirty() );
		}

		if ( changed ) {
			event.preventDefault();
			// The return string is needed for browser compat.
			// See https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event.
			return __( 'The changes you made will be lost if you navigate away from this page.' );
		}
	}).on( 'unload.edit-post', function( event ) {
		if ( ! releaseLock ) {
			return;
		}

		/*
		 * Unload is triggered (by hand) on removing the Thickbox iframe.
		 * Make sure we process only the main document unload.
		 */
		if ( event.target && event.target.nodeName != '#document' ) {
			return;
		}

		var postID = $('#post_ID').val();
		var postLock = $('#active_post_lock').val();

		if ( ! postID || ! postLock ) {
			return;
		}

		var data = {
			action: 'wp-remove-post-lock',
			_wpnonce: $('#_wpnonce').val(),
			post_ID: postID,
			active_post_lock: postLock
		};

		if ( window.FormData && window.navigator.sendBeacon ) {
			var formData = new window.FormData();

			$.each( data, function( key, value ) {
				formData.append( key, value );
			});

			if ( window.navigator.sendBeacon( ajaxurl, formData ) ) {
				return;
			}
		}

		// Fall back to a synchronous POST request.
		// See https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon
		$.post({
			async: false,
			data: data,
			url: ajaxurl
		});
	});

	// Multiple taxonomies.
	if ( $('#tagsdiv-post_tag').length ) {
		window.tagBox && window.tagBox.init();
	} else {
		$('.meta-box-sortables').children('div.postbox').each(function(){
			if ( this.id.indexOf('tagsdiv-') === 0 ) {
				window.tagBox && window.tagBox.init();
				return false;
			}
		});
	}

	// Handle categories.
	$('.categorydiv').each( function(){
		var this_id = $(this).attr('id'), catAddBefore, catAddAfter, taxonomyParts, taxonomy, settingName;

		taxonomyParts = this_id.split('-');
		taxonomyParts.shift();
		taxonomy = taxonomyParts.join('-');
		settingName = taxonomy + '_tab';

		if ( taxonomy == 'category' ) {
			settingName = 'cats';
		}

		// @todo Move to jQuery 1.3+, support for multiple hierarchical taxonomies, see wp-lists.js.
		$('a', '#' + taxonomy + '-tabs').on( 'click', function( e ) {
			e.preventDefault();
			var t = $(this).attr('href');
			$(this).parent().addClass('tabs').siblings('li').removeClass('tabs');
			$('#' + taxonomy + '-tabs').siblings('.tabs-panel').hide();
			$(t).show();
			if ( '#' + taxonomy + '-all' == t ) {
				deleteUserSetting( settingName );
			} else {
				setUserSetting( settingName, 'pop' );
			}
		});

		if ( getUserSetting( settingName ) )
			$('a[href="#' + taxonomy + '-pop"]', '#' + taxonomy + '-tabs').trigger( 'click' );

		// Add category button controls.
		$('#new' + taxonomy).one( 'focus', function() {
			$( this ).val( '' ).removeClass( 'form-input-tip' );
		});

		// On [Enter] submit the taxonomy.
		$('#new' + taxonomy).on( 'keypress', function(event){
			if( 13 === event.keyCode ) {
				event.preventDefault();
				$('#' + taxonomy + '-add-submit').trigger( 'click' );
			}
		});

		// After submitting a new taxonomy, re-focus the input field.
		$('#' + taxonomy + '-add-submit').on( 'click', function() {
			$('#new' + taxonomy).trigger( 'focus' );
		});

		/**
		 * Before adding a new taxonomy, disable submit button.
		 *
		 * @param {Object} s Taxonomy object which will be added.
		 *
		 * @return {Object}
		 */
		catAddBefore = function( s ) {
			if ( !$('#new'+taxonomy).val() ) {
				return false;
			}

			s.data += '&' + $( ':checked', '#'+taxonomy+'checklist' ).serialize();
			$( '#' + taxonomy + '-add-submit' ).prop( 'disabled', true );
			return s;
		};

		/**
		 * Re-enable submit button after a taxonomy has been added.
		 *
		 * Re-enable submit button.
		 * If the taxonomy has a parent place the taxonomy underneath the parent.
		 *
		 * @param {Object} r Response.
		 * @param {Object} s Taxonomy data.
		 *
		 * @return {void}
		 */
		catAddAfter = function( r, s ) {
			var sup, drop = $('#new'+taxonomy+'_parent');

			$( '#' + taxonomy + '-add-submit' ).prop( 'disabled', false );
			if ( 'undefined' != s.parsed.responses[0] && (sup = s.parsed.responses[0].supplemental.newcat_parent) ) {
				drop.before(sup);
				drop.remove();
			}
		};

		$('#' + taxonomy + 'checklist').wpList({
			alt: '',
			response: taxonomy + '-ajax-response',
			addBefore: catAddBefore,
			addAfter: catAddAfter
		});

		// Add new taxonomy button toggles input form visibility.
		$('#' + taxonomy + '-add-toggle').on( 'click', function( e ) {
			e.preventDefault();
			$('#' + taxonomy + '-adder').toggleClass( 'wp-hidden-children' );
			$('a[href="#' + taxonomy + '-all"]', '#' + taxonomy + '-tabs').trigger( 'click' );
			$('#new'+taxonomy).trigger( 'focus' );
		});

		// Sync checked items between "All {taxonomy}" and "Most used" lists.
		$('#' + taxonomy + 'checklist, #' + taxonomy + 'checklist-pop').on(
			'click',
			'li.popular-category > label input[type="checkbox"]',
			function() {
				var t = $(this), c = t.is(':checked'), id = t.val();
				if ( id && t.parents('#taxonomy-'+taxonomy).length )
					$('#in-' + taxonomy + '-' + id + ', #in-popular-' + taxonomy + '-' + id).prop( 'checked', c );
			}
		);

	}); // End cats.

	// Custom Fields postbox.
	if ( $('#postcustom').length ) {
		$( '#the-list' ).wpList( {
			/**
			 * Add current post_ID to request to fetch custom fields
			 *
			 * @ignore
			 *
			 * @param {Object} s Request object.
			 *
			 * @return {Object} Data modified with post_ID attached.
			 */
			addBefore: function( s ) {
				s.data += '&post_id=' + $('#post_ID').val();
				return s;
			},
			/**
			 * Show the listing of custom fields after fetching.
			 *
			 * @ignore
			 */
			addAfter: function() {
				$('table#list-table').show();
			}
		});
	}

	/*
	 * Publish Post box (#submitdiv)
	 */
	if ( $('#submitdiv').length ) {
		stamp = $('#timestamp').html();
		visibility = $('#post-visibility-display').html();

		/**
		 * When the visibility of a post changes sub-options should be shown or hidden.
		 *
		 * @ignore
		 *
		 * @return {void}
		 */
		updateVisibility = function() {
			// Show sticky for public posts.
			if ( $postVisibilitySelect.find('input:radio:checked').val() != 'public' ) {
				$('#sticky').prop('checked', false);
				$('#sticky-span').hide();
			} else {
				$('#sticky-span').show();
			}

			// Show password input field for password protected post.
			if ( $postVisibilitySelect.find('input:radio:checked').val() != 'password' ) {
				$('#password-span').hide();
			} else {
				$('#password-span').show();
			}
		};

		/**
		 * Make sure all labels represent the current settings.
		 *
		 * @ignore
		 *
		 * @return {boolean} False when an invalid timestamp has been selected, otherwise True.
		 */
		updateText = function() {

			if ( ! $timestampdiv.length )
				return true;

			var attemptedDate, originalDate, currentDate, publishOn, postStatus = $('#post_status'),
				optPublish = $('option[value="publish"]', postStatus), aa = $('#aa').val(),
				mm = $('#mm').val(), jj = $('#jj').val(), hh = $('#hh').val(), mn = $('#mn').val();

			attemptedDate = new Date( aa, mm - 1, jj, hh, mn );
			originalDate = new Date(
				$('#hidden_aa').val(),
				$('#hidden_mm').val() -1,
				$('#hidden_jj').val(),
				$('#hidden_hh').val(),
				$('#hidden_mn').val()
			);
			currentDate = new Date(
				$('#cur_aa').val(),
				$('#cur_mm').val() -1,
				$('#cur_jj').val(),
				$('#cur_hh').val(),
				$('#cur_mn').val()
			);

			// Catch unexpected date problems.
			if (
				attemptedDate.getFullYear() != aa ||
				(1 + attemptedDate.getMonth()) != mm ||
				attemptedDate.getDate() != jj ||
				attemptedDate.getMinutes() != mn
			) {
				$timestampdiv.find('.timestamp-wrap').addClass('form-invalid');
				return false;
			} else {
				$timestampdiv.find('.timestamp-wrap').removeClass('form-invalid');
			}

			// Determine what the publish should be depending on the date and post status.
			if ( attemptedDate > currentDate && $('#original_post_status').val() != 'future' ) {
				publishOn = __( 'Schedule for:' );
				$('#publish').val( _x( 'Schedule', 'post action/button label' ) );
			} else if ( attemptedDate <= currentDate && $('#original_post_status').val() != 'publish' ) {
				publishOn = __( 'Publish on:' );
				$('#publish').val( __( 'Publish' ) );
			} else {
				publishOn = __( 'Published on:' );
				$('#publish').val( __( 'Update' ) );
			}

			// If the date is the same, set it to trigger update events.
			if ( originalDate.toUTCString() == attemptedDate.toUTCString() ) {
				// Re-set to the current value.
				$('#timestamp').html(stamp);
			} else {
				$('#timestamp').html(
					'\n' + publishOn + ' <b>' +
					// translators: 1: Month, 2: Day, 3: Year, 4: Hour, 5: Minute.
					__( '%1$s %2$s, %3$s at %4$s:%5$s' )
						.replace( '%1$s', $( 'option[value="' + mm + '"]', '#mm' ).attr( 'data-text' ) )
						.replace( '%2$s', parseInt( jj, 10 ) )
						.replace( '%3$s', aa )
						.replace( '%4$s', ( '00' + hh ).slice( -2 ) )
						.replace( '%5$s', ( '00' + mn ).slice( -2 ) ) +
						'</b> '
				);
			}

			// Add "privately published" to post status when applies.
			if ( $postVisibilitySelect.find('input:radio:checked').val() == 'private' ) {
				$('#publish').val( __( 'Update' ) );
				if ( 0 === optPublish.length ) {
					postStatus.append('<option value="publish">' + __( 'Privately Published' ) + '</option>');
				} else {
					optPublish.html( __( 'Privately Published' ) );
				}
				$('option[value="publish"]', postStatus).prop('selected', true);
				$('#misc-publishing-actions .edit-post-status').hide();
			} else {
				if ( $('#original_post_status').val() == 'future' || $('#original_post_status').val() == 'draft' ) {
					if ( optPublish.length ) {
						optPublish.remove();
						postStatus.val($('#hidden_post_status').val());
					}
				} else {
					optPublish.html( __( 'Published' ) );
				}
				if ( postStatus.is(':hidden') )
					$('#misc-publishing-actions .edit-post-status').show();
			}

			// Update "Status:" to currently selected status.
			$('#post-status-display').text(
				// Remove any potential tags from post status text.
				wp.sanitize.stripTagsAndEncodeText( $('option:selected', postStatus).t