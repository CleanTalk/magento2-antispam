var d = new Date();

function ctSetCookie(c_name,value){
	document.cookie = c_name + "=" + escape(value) + "; path=/";
}

setTimeout(function(){
	ctSetCookie("ct_checkjs", "777b374af06bbb4f6fdbda40727b5c3b");
	ctSetCookie("ct_timezone", d.getTimezoneOffset()/60*(-1));
}, 1000);