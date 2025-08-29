;(function($){
/**
 * jqGrid Greek (el) Translation
 * Alex Cicovic
 * http://www.alexcicovic.com
 * Dual licensed under the MIT and GPL licenses:
 * http://www.opensource.org/licenses/mit-license.php
 * http://www.gnu.org/licenses/gpl.html
 * Save this row = αποθήκευση γραμμής …..(Αποθήκευση Γραμμής, if the first letter of the word must be capital)
 * Restore this row = επαναφορά γραμμής …..(Επαναφορά Γραμμής, if the first letter of the word must be capital)
**/
$.jgrid = $.jgrid || {};
$.extend($.jgrid,{
	defaults : {
		recordtext: "Βλέπεις {0} - {1} από {2}",
	    emptyrecords: "Δεν υπάρχουν εγγραφές για εμφάνιση",
		loadtext: "Φόρτωση...",
		pgtext : "Σελίδα {0} από {1}"
	},
	search : {
	    caption: "Αναζήτηση...",
	    Find: "Εύρεση",
	    Reset: "Επαναφορά",
	    odata: [{ oper:'eq', text:'ίσο'},{ oper:'ne', text:'όχι ίσο'},{ oper:'lt', text:'μικρότερο'},{ oper:'le', text:'μικρότερο ή ίσο'},{ oper:'gt', text:'μεγαλύτερο'},{ oper:'ge', text:'μεγαλύτερο ή ίσο'},{ oper:'bw', text:'ξεκινάει με'},{ oper:'bn', text:'δεν ξεκινάει με'},{ oper:'in', text:'είναι σε'},{ oper:'ni', text:'δεν είναι σε'},{ oper:'ew', text:'τελειώνει με'},{ oper:'en', text:'δεν τελειώνει με'},{ oper:'cn', text:'περιέχει'},{ oper:'nc', text:'δεν περιέχει'},{ oper:'nu', text:'είναι κενό'},{ oper:'nn', text:'δεν είναι κενό'}],
	    groupOps: [	{ op: "AND", text: "Όλες οι συνθήκες να ισχύουν" },	{ op: "OR",  text: "Τουλάχιστον μια συνθήκη να ισχύει" }	],
		operandTitle : "Κάντε κλικ για να επιλέξετε τη λειτουργία της αναζήτησης.",
		resetTitle : "Επαναφορά Δεδομένων Αναζήτησης"
	},
	edit : {
	    addCaption: "Εισαγωγή Εγγραφής",
	    editCaption: "Επεξεργασία Εγγραφής",
	    bSubmit: "Καταχώρηση",
	    bCancel: "Άκυρο",
		bClose: "Κλείσιμο",
		saveData: "Τα δεδομένα έχουν αλλάξει! Θέλετε να Αποθήκευσετε τις αλλαγές?",
		bYes : "Ναι",
		bNo : "Όχι",
		bExit : "Άκυρο",
	    msg: {
	        required:"Το πεδίο είναι απαραίτητο",
	        number:"Το πεδίο δέχεται μόνο αριθμούς",
	        minValue:"Η τιμή πρέπει να είναι μεγαλύτερη ή ίση του ",
	        maxValue:"Η τιμή πρέπει να είναι μικρότερη ή ίση του ",
	        email: "Η διεύθυνση e-mail δεν είναι έγκυρη",
	        integer: "Το πεδίο δέχεται μόνο ακέραιους αριθμούς",
			url: "δεν είναι μια έγκυρη διεύθυνση URL. Απαιτείται το πρόθεμα ('http://' or 'https://')",
			nodefined : " δεν ορίζεται!",
			novalue : " Απαιτείται τιμή επιστροφής!",
			customarray : "H προσαρμοσμένη λειτουργία θα πρέπει να επιστρέψει συστοιχία!",
			customfcheck : "Η προσαρμοσμένη λειτουργία θα πρέπει είναι παρόν σε περίπτωση προσαρμοσμένου ελέγχου!"
		}
	},
	view : {
	    caption: "Δες τις Εγγραφές",
	    bClose: "Κλείσε"
	},
	del : {
	    caption: "Διαγραφή",
	    msg: "Διαγραφή των επιλεγμένων εγγραφών;",
	    bSubmit: "Ναι",
	    bCancel: "Άκυρο"
	},
	nav : {
		edittext: " ",
	    edittitle: "Επεξεργασία επιλεγμένης εγγραφής",
		addtext:" ",
	    addtitle: "Εισαγωγή νέας εγγραφής",
	    deltext: " ",
	    deltitle: "Διαγραφή επιλεγμένης εγγραφής",
	    searchtext: " ",
	    searchtitle: "Εύρεση Εγγραφών",
	    refreshtext: "",
	    refreshtitle: "Ανανέωση Πίνακα",
	    alertcap: "Προσοχή",
	    alerttext: "Δεν έχετε επιλέξει εγγραφή",
		viewtext: "",
		viewtitle: "Δείτε τις επιλεγμένες γραμμές",
		// new custom constants
		columns : "Στήλες",
		showhidecol : "Επέλεξε Στήλες Για Εμφάνιση",
		bulkedit: "Μαζική Επεξεργασία",
		bulkeditskip : "Σημείωση: Τα κενά πεδία θα παραλειφθούν",
		clone : "Κλώνος",
		'export' : "Εξαγωγή",
		'saveRow' : "Αποθήκευση Γραμμής",
		'restoreRow' : "Επαναφορά Γραμμής"	
	},
	col : {
	    caption: "Εμφάνιση / Απόκρυψη Στηλών",
	    bSubmit: "ΟΚ",
	    bCancel: "Άκυρο"
	},
	errors : {
		errcap : "Σφάλμα",
		nourl : "Δεν έχει δοθεί διεύθυνση χειρισμού για τη συγκεκριμένη ενέργεια",
		norecords: "Δεν υπάρχουν εγγραφές προς επεξεργασία",
		model : "Άνισος αριθμός πεδίων colNames/colModel!"
	},
	formatter : {
		integer : {thousandsSeparator: " ", defaultValue: '0'},
		number : {decimalSeparator:".", thousandsSeparator: " ", decimalPlaces: 2, defaultValue: '0.00'},
		currency : {decimalSeparator:".", thousandsSeparator: " ", decimalPlaces: 2, prefix: "", suffix:"", defaultValue: '0.00'},
		date : {
			dayNames:   [
				"Κυρ", "Δευ", "Τρι", "Τετ", "Πεμ", "Παρ", "Σαβ",
				"Κυριακή", "Δευτέρα", "Τρίτη", "Τετάρτη", "Πέμπτη", "Παρασκευή", "Σάββατο"
			],
			monthNames: [
				"Ιαν", "Φεβ", "Μαρ", "Απρ", "Μαι", "Ιουν", "Ιουλ", "Αυγ", "Σεπ", "Οκτ", "Νοε", "Δεκ",
				"Ιανουάριος", "Φεβρουάριος", "Μάρτιος", "Απρίλιος", "Μάιος", "Ιούνιος", "Ιούλιος", "Αύγουστος", "Σεπτέμβριος", "Οκτώβριος", "Νοέμβριος", "Δεκέμβριος"
			],
			AmPm : ["πμ","μμ","ΠΜ","ΜΜ"],
			S: function (j) {return j == 1 || j > 1 ? ['η'][Math.min((j - 1) % 10, 3)] : ''},
			srcformat: 'Y-m-d',
			newformat: 'd/m/Y',
			parseRe : /[Tt\\\/:_;.,\t\s-]/,
			masks : {
	            ISO8601Long:"Y-m-d H:i:s",
	            ISO8601Short:"Y-m-d",
	            ShortDate: "n/j/Y",
	            LongDate: "l, F d, Y",
	            FullDateTime: "l, F d, Y g:i:s A",
	            MonthDay: "F d",
	            ShortTime: "g:i A",
	            LongTime: "g:i:s A",
	            SortableDateTime: "Y-m-d\\TH:i:s",
	            UniversalSortableDateTime: "Y-m-d H:i:sO",
	            YearMonth: "F, Y"
	        },
	        reformatAfterEdit : false
		},
		baseLinkUrl: '',
		showAction: '',
	    target: '',
	    checkbox : {disabled:true},
		idName : 'id'
	}
});
})(jQuery);
