<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */
?><!DOCTYPE html>
<html lang="en">
  <head>
		<meta charset="utf-8">
		<title>CSV Import - Step 1</title>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="description" content="">
		<meta name="author" content="">
		<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
	</head>
	
	<body style="background:#FCFDFD">
		<div class="container">
		<div class="row" style="padding:15px">

			<form class="form-horizontal" method="post" enctype="multipart/form-data">
				<input type="hidden" name="step" value="2">
				<input type="hidden" name="import" value="1">
				<fieldset>

				<!-- Form Name -->
				<legend>CSV Import - Step 1</legend>

				<!-- File Button --> 
				<div class="form-group">
				  <label class="col-md-4 control-label" for="csv_file">CSV File</label>
				  <div class="col-md-4">
					<input accept=".txt,.csv,.tsv" id="csv_file" name="csv_file" class="input-file" type="file">
				  </div>
				</div>

				<!-- Textarea -->
				<div class="form-group">
				  <label class="col-md-4 control-label" for="csv_str">CSV Content</label>
				  <div class="col-md-4">                     
					<textarea rows="10" class="form-control" id="csv_str" name="csv_str" placeholder="Paste here ..."></textarea>
				  </div>
				</div>

				<!-- Check -->
				<div class="form-group">
				  <div class="col-md-4">                     
					<label style="font-weight:normal;"><input id="first_row_label" value="1" name="first_row_label" type="checkbox" checked> First row as label</label>
				  </div>
				</div>
				
				<!-- Check -->
				<div class="form-group">
					<label class="col-md-4 control-label">Delimiter</label>
				  	<div class="col-md-4">                     
						<select id="delimiter" value="," name="delimiter">
						<option value="">Auto-detect</option>
						<option value=",">Comma (,)</option>
						<option value="\t">Tab</option>
						<option value="|">Pipe (|)</option>
						<option value=";">Semicolon (;)</option>
						</select>
				  </div>
				</div>

				<!-- Button -->
				<div class="form-group">
				  <label class="col-md-4 control-label" for="import"></label>
				  <div class="col-md-4">
					<input id="reset" type="reset" class="btn btn-default" value="Reset">
					<button id="import" name="import" class="btn btn-default">Import</button>
				  </div>
				</div>

				</fieldset>
			</form>

		</div>
		</div>

	</body>
</html>