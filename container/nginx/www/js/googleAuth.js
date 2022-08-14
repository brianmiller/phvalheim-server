let b64DecodeUnicode = str =>
  decodeURIComponent(
	Array.prototype.map.call(atob(str), c =>
	  '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)
	).join('')
  )  


let parseJwt = token =>
  JSON.parse(
	b64DecodeUnicode(
	  token.split('.')[1].replace('-', '+').replace('_', '/')
	)
  )


window.isAuthenticated = false;
window.identity = {};
window.token = '';


function handleCredentialResponse(response) {
	window.token = response.credential;
	window.identity = parseJwt(response.credential);
	window.isAuthenticated = true;
	showAuthInfo();
}


function showAuthInfo() {
	if (window.isAuthenticated) { 
		//document.getElementById("authenticated").style.removeProperty('display');
		//document.getElementById("welcome").innerHTML = `Hello <b>${window.identity.name}!</b><img src="${window.identity.picture}" alt="Avatar" style="padding: 0 2rem 0 2rem; border-radius: 50%;">`;
		//document.getElementById("alternative-login").style.setProperty('display', 'none');

		//invalid test token
		//document.getElementById("google_id_token").value = 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjE1NDllMGFlZjU3NGQxYzdiZGQxMzZjMjAyYjhkMjkwNTgwYjE2NWMiLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL2FjY291bnRzLmdvb2dsZS5jb20iLCJuYmYiOjE2NTk2NDA1NjAsImF1ZCI6Ijc1NjA1NTIyMTQ1NS0waHRzM2VnNWMxdWtodDRjMnQ0ajY2YThhbzB1Yzg0YS5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbSIsInN1YiI6IjEwNzk2NTI0NzQ1MTU3NDcxMjQ3NSIsImVtYWlsIjoidGhlb3JpZ2luYWxicmlhbm1pbGxlckBnbWFpbC5jb20iLCJlbWFpbF92ZXJpZmllZCI6dHJ1ZSwiYXpwIjoiNzU2MDU1MjIxNDU1LTBodHMzZWc1YzF1a2h0NGMydDRqNjZhOGFvMHVjODRhLmFwcHMuZ29vZ2xldXNlcmNvbnRlbnQuY29tIiwibmFtZSI6IkJyaWFuIE1pbGxlciIsInBpY3R1cmUiOiJodHRwczovL2xoMy5nb29nbGV1c2VyY29udGVudC5jb20vYS0vQUZkWnVjcXdudE91VFF1eENiaUxwcVJLZmdISExDd2pLQVdUajNNSnNMMzRTeEk9czk2LWMiLCJnaXZlbl9uYW1lIjoiQnJpYW4iLCJmYW1pbHlfbmFtZSI6Ik1pbGxlciIsImlhdCI6MTY1OTY0MDg2MCwiZXhwIjoxNjU5NjQ0NDYwLCJqdGkiOiJiMTVjMTIzYmI3NWI2ZWY5NjA1YTNkZDhiNDExMWU5NjFhNmZmZGY5In0.lmM8fLCgi8SskiKoH7dyWN5ZPWw7jsdOvKDNSSUCzYwiSu0Rf6gQRCMskei4D-Do_zNerzR1wxc3qqm1BcLyjdFSTn5KfAHLTAsdb9MAO06uTuoQC_58qtMz7FnRAoDhwz_rH_azOSl7fWrDzDizXIZMbm_O3qzqFeAsKf0g2T1GO_YKPMQ2GI3DyCPx9JX0-lklHHFvKiMmJruBVoLxnqY8aJH-izjipzU-iX8yl6ybjdFcOFJjoG9_qAS45Qxm-DjwFDXdKZJJvdvyHJ2od2OhPkXdelFCjjVF3Fc_ITkBNM43D0cjbPv2gDsFmqdw9d4s_vUVHtf5W-Q1kD8N8A';
		document.getElementById("google_id_token").value = window.token;
		document.forms['authenticated_form'].submit();
	} else {
		//document.getElementById("authenticated").style.setProperty('display', 'none');
		//document.getElementById("welcome").innerText = 'Hello there!';
		//document.getElementById("alternative-login").style.removeProperty('display');
	}
}


window.onload = function () {
  google.accounts.id.initialize({
	client_id: window.clientId,
	callback: handleCredentialResponse, // We choose to handle the callback in client side, so we include a reference to a function that will handle the response
	auto_select: window.autoLogin, //automatic login
  });

  // You can skip the next instruction if you don't want to show the "Sign-in" button
  google.accounts.id.renderButton(
 	document.getElementById("googleSignInButton"), // Ensure the element exist and it is a div to display correcctly
	{ theme: "outline", size: "large" }  // Customization attributes
  );

  google.accounts.id.prompt(); // Display the One Tap dialog

}
