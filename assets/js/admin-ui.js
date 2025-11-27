( function ( $ ) {
	$( function () {
		const tabs = $( '#kasumi-ai-tabs' );
		const adminData = window.kasumiAiAdmin || {};
		const wpApi = window.wp || {};

		if ( tabs.length ) {
			tabs.tabs( {
				history: false,
				activate: function( event, ui ) {
					// Usuń hash z URL jeśli został dodany
					if ( window.location.hash ) {
						window.history.replaceState( null, '', window.location.pathname + window.location.search );
					}
				}
			} );
		}

		$( '[data-kasumi-tooltip]' ).tooltip( {
			items: '[data-kasumi-tooltip]',
			content: function () {
				return $( this ).attr( 'data-kasumi-tooltip' );
			},
			position: {
				my: 'left+15 center',
				at: 'right center',
			},
		} );

		const fetchModels = function ( control, autoload ) {
			const select = control.find( '[data-kasumi-model]' );
			const provider = select.data( 'kasumi-model' );
			if ( ! provider || ! adminData.ajaxUrl ) {
				return;
			}

			const spinner = control.find( '.kasumi-model-spinner' );
			if ( autoload ) {
				spinner.addClass( 'is-active' );
			}

			const formData = new window.FormData();
			formData.append( 'action', 'kasumi_ai_models' );
			formData.append( 'nonce', adminData.nonce || '' );
			formData.append( 'provider', provider );

			if ( ! wpApi.apiFetch ) {
				return;
			}

			wpApi.apiFetch( {
				url: adminData.ajaxUrl,
				method: 'POST',
				body: formData,
			} ).then( function ( payload ) {
				if ( ! payload.success ) {
					var genericError = ( adminData.i18n && adminData.i18n.error ) ? adminData.i18n.error : 'Error';
					var message = payload.data && payload.data.message ? payload.data.message : genericError;
					throw new Error( message );
				}

				const models = payload.data.models || [];
				const current = select.data( 'current-value' ) || select.val();
				select.empty();

				if ( ! models.length ) {
					const emptyLabel = adminData.i18n && adminData.i18n.noModels ? adminData.i18n.noModels : 'No models';
					select.append(
						$( '<option>' ).text( emptyLabel )
					);
				} else {
					models.forEach( function ( model ) {
						select.append(
							$( '<option>' )
								.val( model.id )
								.text( model.label || model.id )
						);
					} );
				}

				if ( current ) {
					select.val( current );
				}
			} ).catch( function ( error ) {
				const message = error.message || ( adminData.i18n && adminData.i18n.error ) || 'Error';
				window.alert( message );
			} ).finally( function () {
				spinner.removeClass( 'is-active' );
			} );
		};

		$( '.kasumi-model-control' ).each( function () {
			const control = $( this );
			const refresh = control.find( '.kasumi-refresh-models' );

			refresh.on( 'click', function () {
				fetchModels( control, true );
			} );

			if ( '1' === control.data( 'autoload' ) ) {
				fetchModels( control, false );
			}
		} );

		// Inicjalizacja WordPress Color Picker
		if ( $.fn.wpColorPicker ) {
			$( '.wp-color-picker-field' ).wpColorPicker();
		}

		if ( adminData.scheduler ) {
			initScheduler( adminData.scheduler );
		}
	} );

	function initScheduler( config ) {
		const root = document.getElementById( 'kasumi-schedule-manager' );
		const apiFetch = window.wp && window.wp.apiFetch ? window.wp.apiFetch : null;

		if ( ! root || ! apiFetch ) {
			return;
		}

		const elements = {
			form: root.querySelector( '[data-kasumi-schedule-form]' ),
			alert: root.querySelector( '[data-kasumi-schedule-alert]' ),
			table: root.querySelector( '[data-kasumi-schedule-table]' ),
			statusFilter: root.querySelector( '[data-kasumi-filter="status"]' ),
			authorFilter: root.querySelector( '[data-kasumi-filter="author"]' ),
			searchFilter: root.querySelector( '[data-kasumi-filter="search"]' ),
			refreshBtn: root.querySelector( '[data-kasumi-refresh]' ),
			resetBtn: root.querySelector( '[data-kasumi-reset-form]' ),
			postTypeSelect: root.querySelector( '#kasumi-schedule-post-type' ),
			authorSelect: root.querySelector( '#kasumi-schedule-author' ),
			submitBtn: root.querySelector( '[data-kasumi-schedule-submit]' ),
			modelSelect: root.querySelector( '#kasumi-schedule-model' ),
		};

		const state = {
			items: [],
			loading: false,
			editId: null,
			filters: {
				status: '',
				author: '',
				search: '',
				page: 1,
				per_page: 20,
			},
		};

		populateSelect( elements.postTypeSelect, config.postTypes );
		populateSelect( elements.authorSelect, config.authors, true );
		populateSelect( elements.authorFilter, config.authors, true );

		populateSelect( elements.modelSelect, config.models, true );

		if ( elements.form ) {
			elements.form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				saveSchedule();
			} );
		}

		if ( elements.resetBtn ) {
			elements.resetBtn.addEventListener( 'click', function() {
				resetForm();
			} );
		}

		if ( elements.statusFilter ) {
			elements.statusFilter.addEventListener( 'change', function( event ) {
				state.filters.status = event.target.value;
				state.filters.page = 1;
				fetchSchedules();
			} );
		}

		if ( elements.authorFilter ) {
			elements.authorFilter.addEventListener( 'change', function( event ) {
				state.filters.author = event.target.value;
				state.filters.page = 1;
				fetchSchedules();
			} );
		}

		if ( elements.searchFilter ) {
			elements.searchFilter.addEventListener( 'input', debounce( function( event ) {
				state.filters.search = event.target.value;
				state.filters.page = 1;
				fetchSchedules();
			}, 400 ) );
		}

		if ( elements.refreshBtn ) {
			elements.refreshBtn.addEventListener( 'click', function() {
				fetchSchedules();
			} );
		}

		if ( elements.table ) {
			elements.table.addEventListener( 'click', function( event ) {
				const action = event.target.getAttribute( 'data-action' );
				const id = parseInt( event.target.getAttribute( 'data-id' ), 10 );

				if ( ! action || ! id ) {
					return;
				}

				if ( 'edit' === action ) {
					const item = state.items.find( function( entry ) {
						return entry.id === id;
					} );
					if ( item ) {
						fillForm( item );
					}
				}

				if ( 'delete' === action ) {
					if ( window.confirm( config.i18n.deleteConfirm ) ) {
						deleteSchedule( id );
					}
				}

				if ( 'run' === action ) {
					runSchedule( id );
				}
			} );
		}

		resetForm();
		fetchSchedules();

		function populateSelect( select, options, includePlaceholder ) {
			if ( ! select || ! options ) {
				return;
			}

			select.innerHTML = '';

			if ( includePlaceholder ) {
				const option = document.createElement( 'option' );
				option.value = '';
				option.textContent = select.dataset.placeholder || '—';
				select.appendChild( option );
			}

			options.forEach( function( option ) {
				const node = document.createElement( 'option' );
				node.value = option.value || option.id;
				node.textContent = option.label || option.name;
				select.appendChild( node );
			} );
		}

		function setNotice( type, message ) {
			if ( ! elements.alert ) {
				return;
			}

			if ( ! message ) {
				elements.alert.style.display = 'none';
				elements.alert.textContent = '';
				return;
			}

			elements.alert.className = 'notice notice-' + type;
			elements.alert.textContent = message;
			elements.alert.style.display = 'block';
		}

		function serializeForm() {
			if ( ! elements.form ) {
				return {};
			}

			const formData = new window.FormData( elements.form );
			const getValue = function( key, fallback ) {
				const value = formData.get( key );
				return value ? value.toString() : ( fallback || '' );
			};

			return {
				postTitle: getValue( 'postTitle', '' ).trim(),
				status: getValue( 'status', 'draft' ),
				postType: getValue( 'postType', 'post' ),
				postStatus: getValue( 'postStatus', 'draft' ),
				authorId: getValue( 'authorId', '' ),
				publishAt: getValue( 'publishAt', '' ),
				model: getValue( 'model', '' ),
				systemPrompt: getValue( 'systemPrompt', '' ),
				userPrompt: getValue( 'userPrompt', '' ),
			};
		}

		function saveSchedule() {
			const payload = serializeForm();

			if ( 'scheduled' === payload.status && ! payload.publishAt ) {
				setNotice( 'error', config.i18n.error );
				return;
			}

			setNotice( 'info', config.i18n.loading );
			if ( elements.submitBtn ) {
				elements.submitBtn.setAttribute( 'disabled', 'disabled' );
			}

			const method = state.editId ? 'PATCH' : 'POST';
			const url = state.editId ? config.restUrl + '/' + state.editId : config.restUrl;

			apiFetch( {
				url: url,
				method: method,
				headers: {
					'X-WP-Nonce': config.nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( payload ),
			} ).then( function() {
				setNotice( 'success', state.editId ? config.i18n.updated : config.i18n.save );
				resetForm();
				fetchSchedules();
			} ).catch( function() {
				setNotice( 'error', config.i18n.error );
			} ).finally( function() {
				if ( elements.submitBtn ) {
					elements.submitBtn.removeAttribute( 'disabled' );
				}
			} );
		}

		function fetchSchedules() {
			state.loading = true;
			renderTable();

			const params = new window.URLSearchParams();
			Object.entries( state.filters ).forEach( function( entry ) {
				const key = entry[0];
				const value = entry[1];
				if ( value ) {
					params.append( key, value );
				}
			} );

			apiFetch( {
				url: config.restUrl + '?' + params.toString(),
				method: 'GET',
				headers: {
					'X-WP-Nonce': config.nonce,
				},
			} ).then( function( response ) {
				state.items = response.items || [];
				state.loading = false;
				renderTable();
			} ).catch( function() {
				state.items = [];
				state.loading = false;
				setNotice( 'error', config.i18n.error );
				renderTable();
			} );
		}

		function renderTable() {
			if ( ! elements.table ) {
				return;
			}

			if ( state.loading ) {
				elements.table.innerHTML = '<p>' + config.i18n.loading + '</p>';
				return;
			}

			if ( ! state.items.length ) {
				elements.table.innerHTML = '<p>' + config.i18n.empty + '</p>';
				return;
			}

			const rows = state.items.map( function( item ) {
				return [
					'<tr>',
					'<td><strong>' + escapeHtml( item.postTitle || '(Untitled)' ) + '</strong><br><small>' + escapeHtml( item.userPrompt || '' ).slice( 0, 120 ) + '</small></td>',
					'<td>' + formatStatus( item.status ) + '</td>',
					'<td>' + ( item.publishAt ? new Date( item.publishAt ).toLocaleString() : config.i18n.noDate ) + '</td>',
					'<td>' + ( item.authorId || '—' ) + '</td>',
					'<td class="kasumi-table-actions">' +
						'<button type="button" class="button button-link" data-action="edit" data-id="' + item.id + '">' + config.i18n.edit + '</button>' +
						'<button type="button" class="button button-link" data-action="run" data-id="' + item.id + '">' + config.i18n.runAction + '</button>' +
						'<button type="button" class="button button-link button-link-delete" data-action="delete" data-id="' + item.id + '">' + config.i18n.delete + '</button>' +
					'</td>',
					'</tr>',
				].join( '' );
			} );

			elements.table.innerHTML = '<table class="widefat striped"><thead><tr><th>' + config.i18n.taskLabel + '</th><th>' + config.i18n.statusLabel + '</th><th>' + config.i18n.publishLabel + '</th><th>ID</th><th></th></tr></thead><tbody>' + rows.join( '' ) + '</tbody></table>';
		}

		function fillForm( item ) {
			state.editId = item.id;

			elements.form.querySelector( '[name="postTitle"]' ).value = item.postTitle || '';
			elements.form.querySelector( '[name="status"]' ).value = item.status || 'draft';
			elements.form.querySelector( '[name="postType"]' ).value = item.postType || 'post';
			elements.form.querySelector( '[name="postStatus"]' ).value = item.postStatus || 'draft';
			elements.form.querySelector( '[name="authorId"]' ).value = item.authorId || '';
			if ( elements.modelSelect ) {
				elements.modelSelect.value = item.model || '';
			}
			elements.form.querySelector( '[name="systemPrompt"]' ).value = item.systemPrompt || '';
			elements.form.querySelector( '[name="userPrompt"]' ).value = item.userPrompt || '';
			elements.form.querySelector( '[name="publishAt"]' ).value = toDateInputValue( item.publishAt );

			elements.submitBtn.textContent = config.i18n.updated;
		}

		function resetForm() {
			state.editId = null;

			if ( elements.form ) {
				elements.form.reset();
			}

			if ( elements.postTypeSelect && Array.isArray( config.postTypes ) && config.postTypes.length ) {
				elements.postTypeSelect.value = config.postTypes[0].value;
			}

			if ( elements.modelSelect ) {
				elements.modelSelect.value = '';
			}

			elements.submitBtn.textContent = config.i18n.save;
			setNotice( '', '' );
		}

		function deleteSchedule( id ) {
			apiFetch( {
				url: config.restUrl + '/' + id,
				method: 'DELETE',
				headers: {
					'X-WP-Nonce': config.nonce,
				},
			} ).then( function() {
				setNotice( 'success', config.i18n.deleted );
				fetchSchedules();
			} ).catch( function() {
				setNotice( 'error', config.i18n.error );
			} );
		}

		function runSchedule( id ) {
			apiFetch( {
				url: config.restUrl + '/' + id + '/run',
				method: 'POST',
				headers: {
					'X-WP-Nonce': config.nonce,
				},
			} ).then( function() {
				setNotice( 'success', config.i18n.run );
				fetchSchedules();
			} ).catch( function() {
				setNotice( 'error', config.i18n.error );
			} );
		}

		function formatStatus( status ) {
			if ( config.i18n && config.i18n.statusMap && config.i18n.statusMap[ status ] ) {
				return config.i18n.statusMap[ status ];
			}

			return status;
		}

		function toDateInputValue( value ) {
			if ( ! value ) {
				return '';
			}

			const date = new Date( value );

			if ( Number.isNaN( date.getTime() ) ) {
				return '';
			}

			const pad = function( number ) {
				return String( number ).padStart( 2, '0' );
			};

			return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() ) + 'T' + pad( date.getHours() ) + ':' + pad( date.getMinutes() );
		}

		function escapeHtml( text ) {
			if ( ! text ) {
				return '';
			}

			return text.replace( /[&<>"']/g, function( match ) {
				const map = {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#039;',
				};
				return map[ match ];
			} );
		}

		function debounce( callback, delay ) {
			let timeout = null;

			return function( ...args ) {
				window.clearTimeout( timeout );
				timeout = window.setTimeout( function() {
					callback.apply( null, args );
				}, delay );
			};
		}
	}
} )( window.jQuery );
