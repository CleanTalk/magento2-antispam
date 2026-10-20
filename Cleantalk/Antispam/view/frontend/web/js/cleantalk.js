define(['jquery'], function ($) {
    'use strict';

    /**
     * @param {Object} config
     */
    return function (config) {

        let d = new Date(),
            ctTimeMs = new Date().getTime(),
            ctMouseEventTimerFlag = true, //Reading interval flag
            ctMouseData = "[",
            ctMouseDataCounter = 0;

        function ctSetCookie(c_name,value){
            document.cookie = c_name + "=" + escape(value) + "; path=/";
        }

        function ctGetCookie(name) {
            var prefix = name + "=",
                parts = document.cookie.split(";"),
                i,
                part;
            for (i = 0; i < parts.length; i++) {
                part = parts[i].replace(/^\s+/, "");
                if (part.indexOf(prefix) === 0) {
                    return decodeURIComponent(part.substring(prefix.length));
                }
            }
            return "";
        }

        // Same digest PHP hash('sha256') produces for a UTF-8 string.
        function ctSha256(text) {
            function rightRotate(value, amount) {
                return (value >>> amount) | (value << (32 - amount));
            }

            var maxWord = Math.pow(2, 32),
                bytes = unescape(encodeURIComponent(text)),
                length = bytes.length,
                bitLength = length * 8,
                words = [],
                hash = [],
                k = [],
                prime = 2,
                primeCount = 0,
                composite = {},
                i,
                j,
                result = "";

            for (; primeCount < 64; prime++) {
                if (!composite[prime]) {
                    for (i = prime * prime; i < 313; i += prime) {
                        composite[i] = 1;
                    }
                    hash[primeCount] = (Math.pow(prime, 0.5) * maxWord) | 0;
                    k[primeCount] = (Math.pow(prime, 1 / 3) * maxWord) | 0;
                    primeCount++;
                }
            }

            bytes += "\x80";
            while ((bytes.length % 64) !== 56) {
                bytes += "\x00";
            }
            for (i = 0; i < bytes.length; i++) {
                words[i >> 2] |= bytes.charCodeAt(i) << ((3 - i) % 4) * 8;
            }
            words[words.length] = (bitLength / maxWord) | 0;
            words[words.length] = bitLength;

            for (j = 0; j < words.length;) {
                var w = words.slice(j, j += 16),
                    oldHash = hash.slice(0),
                    i2;
                hash = hash.slice(0, 8);
                for (i2 = 0; i2 < 64; i2++) {
                    var w15 = w[i2 - 15],
                        w2 = w[i2 - 2],
                        a = hash[0],
                        e = hash[4],
                        temp1 = hash[7]
                            + (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25))
                            + ((e & hash[5]) ^ ((~e) & hash[6]))
                            + k[i2]
                            + (w[i2] = (i2 < 16) ? w[i2] : (
                                w[i2 - 16]
                                + (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3))
                                + w[i2 - 7]
                                + (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10))
                            ) | 0),
                        temp2 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22))
                            + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));
                    hash = [(temp1 + temp2) | 0].concat(hash);
                    hash[4] = (hash[4] + temp1) | 0;
                }
                for (i2 = 0; i2 < 8; i2++) {
                    hash[i2] = (hash[i2] + oldHash[i2]) | 0;
                }
            }

            for (i = 0; i < 8; i++) {
                for (j = 3; j + 1; j--) {
                    var b = (hash[i] >> (j * 8)) & 255;
                    result += ((b < 16) ? "0" : "") + b.toString(16);
                }
            }
            return result;
        }

        // Plant the cookie test in the browser. PHP setcookie() on the page
        // response adds Set-Cookie and Varnish then refuses to cache it.
        (function ctPlantCookies() {
            var names = [],
                check = config.jsKey || "",
                referer = document.referrer || "",
                timestamp = String(Math.floor(new Date().getTime() / 1000)),
                landing = ctGetCookie("ct_site_landing_ts"),
                payload;

            if (referer) {
                ctSetCookie("ct_prev_referer", referer);
                names.push("ct_prev_referer");
                check += referer;
            }
            ctSetCookie("ct_timestamp", timestamp);
            names.push("ct_timestamp");
            check += timestamp;

            if (!landing) {
                landing = timestamp;
                ctSetCookie("ct_site_landing_ts", landing);
            }
            names.push("ct_site_landing_ts");
            check += landing;

            payload = {
                cookies_names: names,
                check_value: ctSha256(check)
            };
            ctSetCookie("ct_cookies_test", JSON.stringify(payload));
        })();

        setTimeout(function(){
            ctSetCookie("ct_checkjs", config.jsKey);
        }, 1000);
        ctSetCookie("ct_ps_timestamp", Math.floor(new Date().getTime()/1000));
        ctSetCookie("ct_timezone", d.getTimezoneOffset()/60*(-1));
        ctSetCookie("ct_fkp_timestamp", "0");
        ctSetCookie("ct_pointer_data", "0");
        //Reading interval
        var ctMouseReadInterval = setInterval(function(){
            ctMouseEventTimerFlag = true;
        }, 150);

        //Writting interval
        var ctMouseWriteDataInterval = setInterval(function(){
            var ctMouseDataToSend = ctMouseData.slice(0,-1).concat("]");
            ctSetCookie("ct_pointer_data", ctMouseDataToSend);
        }, 1200);

        //Stop observing function
        function ctMouseStopData(){
            if(typeof window.addEventListener == "function")
                window.removeEventListener("mousemove", ctFunctionMouseMove);
            else
                window.detachEvent("onmousemove", ctFunctionMouseMove);
            clearInterval(ctMouseReadInterval);
            clearInterval(ctMouseWriteDataInterval);
        }
        //Logging mouse position each 300 ms
        var ctFunctionMouseMove = function output(event){
            if(ctMouseEventTimerFlag == true){
                var mouseDate = new Date();
                ctMouseData += "[" + Math.round(event.pageY) + "," + Math.round(event.pageX) + "," + Math.round(mouseDate.getTime() - ctTimeMs) + "],";
                ctMouseDataCounter++;
                ctMouseEventTimerFlag = false;
                if(ctMouseDataCounter >= 100)
                    ctMouseStopData();
            }
        };

        //Stop key listening function
        function ctKeyStopStopListening(){
            if(typeof window.addEventListener == "function"){
                window.removeEventListener("mousedown", ctFunctionFirstKey);
                window.removeEventListener("keydown", ctFunctionFirstKey);
            }else{
                window.detachEvent("mousedown", ctFunctionFirstKey);
                window.detachEvent("keydown", ctFunctionFirstKey);
            }
        }

        //Writing first key press timestamp
        var ctFunctionFirstKey = function output(event){
            var KeyTimestamp = Math.floor(new Date().getTime()/1000);
            ctSetCookie("ct_fkp_timestamp", KeyTimestamp);
            ctKeyStopStopListening();
        };

        if(typeof window.addEventListener == "function"){
            window.addEventListener("mousemove", ctFunctionMouseMove);
            window.addEventListener("mousedown", ctFunctionFirstKey);
            window.addEventListener("keydown", ctFunctionFirstKey);
        }else{
            window.attachEvent("onmousemove", ctFunctionMouseMove);
            window.attachEvent("mousedown", ctFunctionFirstKey);
            window.attachEvent("keydown", ctFunctionFirstKey);
        }

    }
});
