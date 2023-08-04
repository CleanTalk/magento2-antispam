define(['jquery'], function ($) {
    'use strict';

    /**
     * @param {Object} config
     */
    return function (config) {

        /**
         * Handle external forms
         */
        function ctProtectExternal() {
            clearInterval(dynamicRenderedFormInterval);
            console.log('ctProtectExternal');
            for (let i = 0; i < document.forms.length; i++) {
                if (document.forms[i].cleantalk_hidden_action === undefined &&
                    document.forms[i].cleantalk_hidden_method === undefined) {
                    // current form
                    const currentForm = document.forms[i];

                    if (typeof(currentForm.action) == 'string') {

                        // Ajax checking for the integrated forms
                        if ( isIntegratedForm(currentForm) ) {
                            console.log('isIntegratedForm');
                            apbctProcessExternalForm(currentForm, i, document);

                            // Common flow - modify form's action
                        } else if (currentForm.action.indexOf('http://') !== -1 ||
                            currentForm.action.indexOf('https://') !== -1) {
                            console.log('currentForm.action.indexOf');
                            let tmp = currentForm.action.split('//');
                            tmp = tmp[1].split('/');
                            const host = tmp[0].toLowerCase();

                            if (host !== location.hostname.toLowerCase()) {
                                const ctAction = document.createElement('input');
                                ctAction.name = 'cleantalk_hidden_action';
                                ctAction.value = currentForm.action;
                                ctAction.type = 'hidden';
                                currentForm.appendChild(ctAction);

                                const ctMethod = document.createElement('input');
                                ctMethod.name = 'cleantalk_hidden_method';
                                ctMethod.value = currentForm.method;
                                ctMethod.type = 'hidden';

                                currentForm.method = 'POST';

                                currentForm.appendChild(ctMethod);

                                currentForm.action = document.location;
                            }
                        }
                    }
                }
            }
        }

        window.onload = function() {
            console.log('window.onload');
            console.log(config);
            if ( ! +config.externalForms ) {
                return;
            }

            setTimeout(function() {
                ctProtectExternal();
                catchDynamicRenderedForm();
            }, 1500);
        };

        let dynamicRenderedFormInterval = null;

        /**
         * Catching dynamic rendered forms
         * 
         * @return {void}
         */
        function catchDynamicRenderedForm() {
            if (isIntegratedDynamicFormOnPage()) {
                dynamicRenderedFormInterval = setInterval(function() {
                    ctProtectExternal();
                }, 1000);
            }
        }

        /**
         * Checking the dynamic rendered forms
         * 
         * @return {boolean}
         */
        function isIntegratedDynamicFormOnPage() {
            let result = false;

            document.querySelectorAll('script[src]').forEach(el => {
                if (el.src.includes('ctctcdn.com')) result = true;
            });

            return result;
        }

        /**
         * Checking the form integration
         * @param {HTMLElement} formObj
         * @return {boolean}
         */
        function isIntegratedForm(formObj) {
            const formId = formObj.getAttribute('id') !== null ? formObj.getAttribute('id') : '';
            if (
                formId.indexOf('ctct_form_') !== -1
            ) {
                return true;
            }
            return false;
        }

        /**
         * Process external forms
         * @param {HTMLElement} currentForm
         * @param {int} iterator
         * @param {HTMLElement} documentObject
         */
        function apbctProcessExternalForm(currentForm, iterator, documentObject) {
            console.log('apbctProcessExternalForm');
            const cleantalkPlaceholder = document.createElement('i');
            cleantalkPlaceholder.className = 'cleantalk_placeholder';
            cleantalkPlaceholder.style = 'display: none';

            currentForm.parentElement.insertBefore(cleantalkPlaceholder, currentForm);

            // Deleting form to prevent submit event
            const prev = currentForm.previousSibling;
            const formHtml = currentForm.outerHTML;
            const formOriginal = currentForm;

            // Remove the original form
            currentForm.parentElement.removeChild(currentForm);

            // Insert a clone
            const placeholder = document.createElement('div');
            placeholder.innerHTML = formHtml;
            prev.after(placeholder.firstElementChild);

            const forceAction = document.createElement('input');
            forceAction.name = 'action';
            forceAction.value = 'cleantalk_force_ajax_check';
            forceAction.type = 'hidden';

            const reUseCurrentForm = documentObject.forms[iterator];

            reUseCurrentForm.appendChild(forceAction);
            reUseCurrentForm.apbctPrev = prev;
            reUseCurrentForm.apbctFormOriginal = formOriginal;

            documentObject.forms[iterator].onsubmit = function(event) {
                event.preventDefault();
                console.log('send');
                sendAjaxCheckingFormData(event.currentTarget);
            };
        }

        /**
         * Sending Ajax for checking form data
         * @param {HTMLElement} form
         */
        function sendAjaxCheckingFormData(form) {
            const data = {};
            let elems = form.elements;
            elems = Array.prototype.slice.call(elems);

            elems.forEach( function( elem, y ) {
                if ( elem.name === '' ) {
                    data['input_' + y] = elem.value;
                } else {
                    data[elem.name] = elem.value;
                }
            });

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: data,
                complete: function(response) {
                    if ( result.apbct === undefined || ! +result.apbct.blocked ) {
                        const formNew = form;
                        form.parentElement.removeChild(form);
                        const prev = form.apbctPrev;
                        const formOriginal = form.apbctFormOriginal;
                        let mauticIntegration = false;

                        apbctReplaceInputsValuesFromOtherForm(formNew, formOriginal);

                        // mautic forms integration
                        if (formOriginal.id.indexOf('mautic') !== -1) {
                            mauticIntegration = true;
                        }

                        prev.after( formOriginal );

                        // Clear visible_fields input
                        for (const el of formOriginal.querySelectorAll('input[name="apbct_visible_fields"]')) {
                            el.remove();
                        }

                        for (const el of formOriginal.querySelectorAll('input[value="cleantalk_force_ajax_check"]')) {
                            el.remove();
                        }

                        // Common click event
                        let submButton = formOriginal.querySelectorAll('button[type=submit]');
                        if ( submButton.length !== 0 ) {
                            submButton[0].click();
                            if (mauticIntegration) {
                                setTimeout(function() {
                                    ctProtectExternal();
                                }, 1500);
                            }
                            return;
                        }

                        submButton = formOriginal.querySelectorAll('input[type=submit]');
                        if ( submButton.length !== 0 ) {
                            submButton[0].click();
                            return;
                        }

                        // ConvertKit direct integration
                        submButton = formOriginal.querySelectorAll('button[data-element="submit"]');
                        if ( submButton.length !== 0 ) {
                            submButton[0].click();
                            return;
                        }

                        // Paypal integration
                        submButton = formOriginal.querySelectorAll('input[type="image"][name="submit"]');
                        if ( submButton.length !== 0 ) {
                            submButton[0].click();
                        }
                    }
                    if (result.apbct !== undefined && +result.apbct.blocked) {
                        ctParseBlockMessage(result);
                    }
                },
                error: function (xhr, status, errorThrown) {
                    console.log('Error happens. Try again.');
                }
            });
        }

        function ctParseBlockMessage(response) {
            if (typeof response.apbct !== 'undefined') {
                response = response.apbct;
                if (response.blocked) {
                    document.dispatchEvent(
                        new CustomEvent( 'apbctAjaxBockAlert', {
                            bubbles: true,
                            detail: {message: response.comment},
                        } ),
                    );

                    // Show the result by modal
                    cleantalkModal.loaded = response.comment;
                    cleantalkModal.open();

                    if (+response.stop_script === 1) {
                        window.stop();
                    }
                }
            }
        }

        /* Cleantalk Modal object */
        let cleantalkModal = {

            // Flags
            loaded: false,
            loading: false,
            opened: false,
            opening: false,

            // Methods
            load: function( action ) {
                if ( ! this.loaded ) {
                    this.loading = true;
                    let callback = function( result, data, params, obj ) {
                        cleantalkModal.loading = false;
                        cleantalkModal.loaded = result;
                        document.dispatchEvent(
                            new CustomEvent( 'cleantalkModalContentLoaded', {
                                bubbles: true,
                            } ),
                        );
                    };
                    // eslint-disable-next-line camelcase
                    if ( typeof apbct_admin_sendAJAX === 'function' ) {
                        apbct_admin_sendAJAX( {'action': action}, {'callback': callback, 'notJson': true} );
                    } else {
                        apbct_public_sendAJAX( {'action': action}, {'callback': callback, 'notJson': true} );
                    }
                }
            },

            open: function() {
                /* Cleantalk Modal CSS start */
                let renderCss = function() {
                    let cssStr = '';
                    // eslint-disable-next-line guard-for-in
                    for ( const key in this.styles ) {
                        cssStr += key + ':' + this.styles[key] + ';';
                    }
                    return cssStr;
                };
                let overlayCss = {
                    styles: {
                        'z-index': '9999999999',
                        'position': 'fixed',
                        'top': '0',
                        'left': '0',
                        'width': '100%',
                        'height': '100%',
                        'background': 'rgba(0,0,0,0.5)',
                        'display': 'flex',
                        'justify-content': 'center',
                        'align-items': 'center',
                    },
                    toString: renderCss,
                };
                let innerCss = {
                    styles: {
                        'position': 'relative',
                        'padding': '30px',
                        'background': '#FFF',
                        'border': '1px solid rgba(0,0,0,0.75)',
                        'border-radius': '4px',
                        'box-shadow': '7px 7px 5px 0px rgba(50,50,50,0.75)',
                    },
                    toString: renderCss,
                };
                let closeCss = {
                    styles: {
                        'position': 'absolute',
                        'background': '#FFF',
                        'width': '20px',
                        'height': '20px',
                        'border': '2px solid rgba(0,0,0,0.75)',
                        'border-radius': '15px',
                        'cursor': 'pointer',
                        'top': '-8px',
                        'right': '-8px',
                        'box-sizing': 'content-box',
                    },
                    toString: renderCss,
                };
                let closeCssBefore = {
                    styles: {
                        'content': '""',
                        'display': 'block',
                        'position': 'absolute',
                        'background': '#000',
                        'border-radius': '1px',
                        'width': '2px',
                        'height': '16px',
                        'top': '2px',
                        'left': '9px',
                        'transform': 'rotate(45deg)',
                    },
                    toString: renderCss,
                };
                let closeCssAfter = {
                    styles: {
                        'content': '""',
                        'display': 'block',
                        'position': 'absolute',
                        'background': '#000',
                        'border-radius': '1px',
                        'width': '2px',
                        'height': '16px',
                        'top': '2px',
                        'left': '9px',
                        'transform': 'rotate(-45deg)',
                    },
                    toString: renderCss,
                };
                let bodyCss = {
                    styles: {
                        'overflow': 'hidden',
                    },
                    toString: renderCss,
                };
                let cleantalkModalStyle = document.createElement( 'style' );
                cleantalkModalStyle.setAttribute( 'id', 'cleantalk-modal-styles' );
                cleantalkModalStyle.innerHTML = 'body.cleantalk-modal-opened{' + bodyCss + '}';
                cleantalkModalStyle.innerHTML += '#cleantalk-modal-overlay{' + overlayCss + '}';
                cleantalkModalStyle.innerHTML += '#cleantalk-modal-close{' + closeCss + '}';
                cleantalkModalStyle.innerHTML += '#cleantalk-modal-close:before{' + closeCssBefore + '}';
                cleantalkModalStyle.innerHTML += '#cleantalk-modal-close:after{' + closeCssAfter + '}';
                document.body.append( cleantalkModalStyle );
                /* Cleantalk Modal CSS end */

                let overlay = document.createElement( 'div' );
                overlay.setAttribute( 'id', 'cleantalk-modal-overlay' );
                document.body.append( overlay );

                document.body.classList.add( 'cleantalk-modal-opened' );

                let inner = document.createElement( 'div' );
                inner.setAttribute( 'id', 'cleantalk-modal-inner' );
                inner.setAttribute( 'style', innerCss );
                overlay.append( inner );

                let close = document.createElement( 'div' );
                close.setAttribute( 'id', 'cleantalk-modal-close' );
                inner.append( close );

                let content = document.createElement( 'div' );
                if ( this.loaded ) {
                    const urlRegex = /(https?:\/\/[^\s]+)/g;
                    const serviceContentRegex = /.*\/inc/g;
                    if (serviceContentRegex.test(this.loaded)) {
                        content.innerHTML = this.loaded;
                    } else {
                        content.innerHTML = this.loaded.replace(urlRegex, '<a href="$1" target="_blank">$1</a>');
                    }
                } else {
                    content.innerHTML = 'Loading...';
                    // @ToDo Here is hardcoded parameter. Have to get this from a 'data-' attribute.
                    this.load( 'get_options_template' );
                }
                content.setAttribute( 'id', 'cleantalk-modal-content' );
                inner.append( content );

                this.opened = true;
            },

            close: function() {
                document.body.classList.remove( 'cleantalk-modal-opened' );
                document.getElementById( 'cleantalk-modal-overlay' ).remove();
                document.getElementById( 'cleantalk-modal-styles' ).remove();
                document.dispatchEvent(
                    new CustomEvent( 'cleantalkModalClosed', {
                        bubbles: true,
                    } ),
                );
            },

        };

        /* Cleantalk Modal helpers */
        document.addEventListener('click', function( e ) {
            if ( e.target && (e.target.id === 'cleantalk-modal-overlay' || e.target.id === 'cleantalk-modal-close') ) {
                cleantalkModal.close();
            }
        });
        document.addEventListener('cleantalkModalContentLoaded', function( e ) {
            if ( cleantalkModal.opened && cleantalkModal.loaded ) {
                document.getElementById( 'cleantalk-modal-content' ).innerHTML = cleantalkModal.loaded;
            }
        });
    }
});
