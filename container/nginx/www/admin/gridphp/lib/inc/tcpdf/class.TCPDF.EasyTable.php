<?php

//---------------------------------------------------------------------------
// require '_DenyDirectAccess.php';  // basic class file protector
//---------------------------------------------------------------------------

//============================================================+
// File name:   class.TCPDF.EasyTable.php
// Begin: 2009-12-01
// Last Update : 2010-02-23
// Author      : Bretton Eveleigh
// Version     : 0.4.3 BETA

// This class extends the functionality of TCPDF for easy
// formatting of data(text/images) in a tabular structure
// mimicing and improving on the EzTable method of the
// ROS PDF lib

require_once("tcpdf.php"); // include the PDF library
require_once("class.PDFImage.php"); // include PDF image class

class TCPDF_EasyTable extends TCPDF{

    private $_CellAlignment;
    private $_CellWidths;
    private $_TableHeaders;
    private $_TableData;
    private $_CellFillStyle = 0;
    private $_CellFontColor;
    private $_FillCell = 1;
    private $_FillImageCell = true;
    private $_HCellSpace = 0;
    private $_VCellSpace = 0;
    private $_HeaderCellsFillColor;
    private $_HeaderCellsFontColor;
    private $_HeaderCellsFontStyle; // added 25-01-2010... to change the style of the column header text
    private $_HeaderCellsFixedHeight;
    private $_CellMinimumHeight; // if set... if cell height is less than this it will be forced up to, table header cells are excluded
    private $_CellFixedHeight; // if set then the max cell height is not auto-calc, and is set manually...
    private $_AutoRepeatTableHeader = true; // by default the table header is repeated on each page...
    private $_HeaderFirstTablePerPageOnly = false; // only repeat the table header on the first table per page...
    private $_IsTableHeader = false;
    private $_TableRowFillColors; // added 26-01-2010... if defined is multi dim array of RBG colors, per row, indexed same as _TableData... RGB values only
    private $_FooterExclusionZone = 0; // the area the footer, where the table should not enter, added to the bottom margin
    private $_TableX;
    private $_TableY;
    private $_PageAdded = false;

    /**
    * Initiates the output of EasyTable to PDF, after all style/control params have been set
    * @param array $tableData The multi-dim array representing table rows/cells for output
    * @param array $tableHeader The array of table column headers, can be null for no table header
    * @return null
    * @author Bretton Eveleigh
    * @access public
    * @since 0.1 BETA (2009-12-01)
    */

    public function EasyTable($tableData, $tableHeader=null) {

        $PageDims = $this->getPageDimensions();

        $PageBottomLimit = $PageDims['hk'] - ($PageDims['bm']+$this->_FooterExclusionZone);
        $PageExtentY = $PageDims['hk'] - ($PageDims['bm'] + $PageDims['tm']);

        //$TCPDF_AutoPageBreak = $this->AutoPageBreak;

        //$this->SetAutoPageBreak(false,$PageDims['bm']);

		// set image to pdfimage class
		foreach($tableData as &$tableDRow)
			foreach($tableDRow as &$cellData)
			{
				// regex to get img src
				preg_match("/<img.+?src=[\"'](.+?)[\"'].*?>/", $cellData, $match);
				
                $txt = strip_tags($cellData);

                // process in case of only image tag with no text html
                if (!empty($txt))
                    continue;
                
                if (empty($match))
					continue;

				$src = $match[1];
				if ( strpos($cellData, 'jpg') > 0 || strpos($cellData, 'png') > 0)
				{
					if (!empty($src))
					{
						if(strstr($src,"://") == false)
						{
							// make relative to absolute path
							$protocol = ( (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on") || $_SERVER["SERVER_PORT"] == "443" ) ? "https" : "http";
							$url = "$protocol://".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
							$url = substr($url, 0, strrpos($url,"/")+1);
							$src = $url . $src;
                        }
                        
						// get ext
						$p = strrpos($src, '.');
                        $ext = substr($src,$p+1,3);

						// save file to cache folder from url
						$tmp = "temp".md5($src).".$ext";
						if (!file_exists(K_PATH_CACHE.$tmp))
						{
							$ch = curl_init($src);
							$fp = fopen(K_PATH_CACHE.$tmp, 'wb');
							curl_setopt($ch, CURLOPT_FILE, $fp);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                            curl_setopt($ch, CURLOPT_HEADER, 0);   
                            curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, false);
							curl_exec($ch);
							curl_close($ch);
							fclose($fp);
						}
						// skip bad image
						if (filesize(K_PATH_CACHE.$tmp) < 700)
						{
							$cellData = "";
							break;
						}
                        
						// get dim
						list($width, $height) = getimagesize(K_PATH_CACHE.$tmp);
                        
						$cellData = new PDFImage($tmp, K_PATH_CACHE, $height/7, $width/7 ,null,"$ext");
						// azg: fixed image to fit in cell
						// $cellData->ScaleLongestSideTo(60);
						$cellData->SetVerticalAlignment('top');
						// $cellData->SetHorizontalAlignment('center');
					}
					else
					{
						// if absolute / relative FS path
						$cellData = new PDFImage(substr($cellData, strrpos($cellData, '/') + 1), substr($cellData, 0, strrpos($cellData, '/') + 1), 10,30,null,"$ext");
					}
				}
				else
				{
					// remove non-jpg <img> tag from data
					$cellData = str_replace($match[0],"",$cellData);
				}
			}
			
        $this->_TableHeaders = $tableHeader;
        $this->_TableData = $tableData;

        // rendering the table headers, will be done automatically on following pages:

        if($this->_TableHeaders) $this->_ProcessTableHeader();

        $tableRowCount = sizeof($this->_TableData);

        #echo "<table border=1 cellspacing=3 cellpadding=3>";
        #echo "<tr>";

        #echo "<td><b>TRi</b></td>";
        #echo "<td><b>Page</b></td>";
        #echo "<td><b>TR Height</b></td>";
        #echo "<td><b>Y Coord</b></td>";
        #echo "<td><b>Extent Y</b></td>";
        #echo "<td><b>-.-</b></td>";
        #echo "<td><b>-.-</b></td>";
        #echo "<td><b>-.-</b></td>";

        #echo "</tr>";

        //if($this->_TableY) $this->y = $this->_TableY;

        for($TR=0;$TR<$tableRowCount;$TR++){ // loop though rows...

            if($this->_TableX) $this->x = $this->_TableX; // needs to be set per row...
            
            $tableRow = $this->_TableData[$TR];

            // define the fill style, per row
            switch((int) $this->_CellFillStyle){

                // fill all cells
                case 1: $this->_FillCell = 1; break;

                // fill alternate cells:
                case 2:

                    if($this->_FillCell == 0){

                        $this->_FillCell = 1;

                    }else{

                        $this->_FillCell = 0;

                    }

                break;

            }

            // get the row height:
            $rowHeight = $this->_GetRowHeight($tableRow, $TR);

            //echo $TR.". page: ".$this->page." returned height: ".$rowHeight."... co-ord y: ".$this->y."... page extent: ".$PageExtentY."<BR/>";

            #echo "<tr>";

            #echo "<td><b>".$TR."</b></td>";
            #echo "<td><b>".$this->page."</b></td>";
            #echo "<td><b>".$rowHeight."</b></td>";
            #echo "<td><b>".$this->y."</b></td>";
            #echo "<td><b>".$PageExtentY."</b></td>";
            #echo "<td><b></b></td>";
            #echo "<td><b></b></td>";
            #echo "<td><b></b></td>";

            #echo "</tr>";

            // if row height is too big for page... raise error, notify user:
            if($rowHeight > $PageExtentY){

                throw new Exception(
                    "Row height violation detected.
                     The cell height is too large to fit on a
                     single page, single cells cannot span
                     more than 1 page. Consider splitting
                     the text into multiple cells, or try
                     reducing the font size",
                     100001);

            // check if row fits, else new page
            }else if(($this->y + $rowHeight) > $PageBottomLimit){

                //$this->_PageAdded = true;

                $this->AddPage();

                $this->_ProcessTableHeader(true);
                
            }

            $startX = $this->x;

            $this->_ProcessTableRowCells($tableRow, $rowHeight, $TR);

            $this->SetY(($this->y+$rowHeight));

            $this->x = $startX; //maintain x co-ord after each cell write

            // add vertical row cell spacing, if defined:
            if($this->_VCellSpace) $this->y += (float) $this->_VCellSpace;

        }

        //$this->_PageAdded = false;

        #echo "</table>";

        //$this->SetAutoPageBreak($TCPDF_AutoPageBreak, $PageDims['bm']); // restore the auto paging setting
        
    }

    /**
    * Writes table header to PDF, after processing
    * related settings, table headers [priv $_TableHeaders]
    * are defined/supplied via EasyTable method
    * @return null
    * @author Bretton Eveleigh
    * @access private
    * @since 0.1 (2009-12-01)
    */

    // PROCESS/PRINT TO PDF METHODS:
    private function _ProcessTableHeader($PageAdded=false){

        /*
         * it is permissable that the table does not have
         * column headers, so if they are not defined, then
         * abort processing them
         */

        if(!$this->_TableHeaders) return;

        if($PageAdded){
       //     echo "Page Added...";
        }else{
       //     echo "Page Not Added...";
        }

        //if($this->_HeaderFirstTablePerPageOnly && !$PageAdded) return;


        if($this->_TableX) $this->x = $this->_TableX;
        //if($this->_TableY) $this->y = $this->_TableY;

        // store current settings:
        $cellFillStyle = $this->_CellFillStyle;
        $cellFillColor = $this->FillColor;
        $cellFontColor = $this->TextColor;
        $cellFontStyle = $this->FontStyle;

        // apply header specific settings:
        if($this->_HeaderCellsFillColor){
            $this->SetFillColor(
                $this->_HeaderCellsFillColor['R'],
                $this->_HeaderCellsFillColor['G'],
                $this->_HeaderCellsFillColor['B']
            );
        }

        if($this->_HeaderCellsFontColor){
            $this->SetTextColor(
                $this->_HeaderCellsFontColor['R'],
                $this->_HeaderCellsFontColor['G'],
                $this->_HeaderCellsFontColor['B']
            );
        }

        if($this->_HeaderCellsFontStyle){

            $this->SetFont($this->FontFamily, $this->_HeaderCellsFontStyle, $this->FontSizePt);

        }

        $this->_FillCell = 1; // fill cells

        $this->_IsTableHeader = true;

        $rowHeight = $this->_GetRowHeight($this->_TableHeaders,0);

        $this->_ProcessTableRowCells($this->_TableHeaders,$rowHeight);

        $this->_IsTableHeader = false;

        // restore original settings
        $this->SetFont($this->FontFamily, $cellFontStyle, $this->FontSizePt);
        $this->_CellFillStyle = $cellFillStyle;
        $this->FillColor = $cellFillColor;
        $this->TextColor = $cellFontColor;

        $this->Ln();

        if($this->_VCellSpace) $this->y += (float) $this->_VCellSpace;

    }

    /**
    * Writes table row cells to PDF, after processing
    * related settings, table data(rows/cells) [priv $_TableData]
    * are defined/supplied via EasyTable method
    * @param array $tableRow an array of cell data - HTML/text or image
    * @param int $rowIndex the array index of the table row being processed
    * @author Bretton Eveleigh
    * @access private
    * @since 0.1 (2009-12-01)
    */

    private function _ProcessTableRowCells($tableRow, $rowHeight, $rowIndex=0){

        //echo "init row index: ".$rowIndex."<br/>";

        if(!$this->_IsTableHeader){

            if($this->_CellFontColor && is_array($this->_CellFontColor)){

                $RGB = $this->_CellFontColor;
          
                $this->SetTextColor($RGB['R'], $RGB['G'], $RGB['B']);
                  
            }else{
          
                $this->SetTextColor(0, 0, 0); //RGB
         
            }

        }

        $curFillColor = $this->FillColor;
        $curFillStyle = $this->_FillCell;

        /**
         * if a custom row fill color is defined... process it:
         *
         * note that we don't override the image cells fill style... and ignore header row:
         *
         */

       if(!$this->_IsTableHeader && $this->_TableRowFillColors && isset($this->_TableRowFillColors[$rowIndex])){ // check if a custom row bgcolor has been assigned

           $RGB = $this->_TableRowFillColors[$rowIndex];

           $this->SetFillColor($RGB[0],$RGB[1],$RGB[2]);

           $this->_FillCell = 1;

       }


        $cellIndex = 0; // reset the cell index...

        $imageWidth = 0;

        $imageCellWidth = 0;

        //echo $rowIndex." - - ".$maxRowHeight."<br/>";

        //generate the cells of PDF table:
        foreach($tableRow as $cellData){ // loop through cells in row

            $cellWidth = $this->_GetCellWidth($cellIndex); // get the cell width

            if(is_object($cellData) && !is_string($cellData)){ //process the string... into table cell...

                $className = strtolower(get_class($cellData));

                switch($className){

                    case 'pdfimage':    // place the PDF image...
                        $imageWidth = $cellData->GetImageWidth();

                        $imageCellWidth = (float) $cellWidth;

                        $this->_ProcessMultiCellImage($cellData, $cellWidth, $rowHeight);

                    break;

                    case 'simplexmlelement':    // convert and process as HTML string...

                        $this->_ProcessMultiCellText($cellData, $cellWidth, $rowHeight, $cellIndex);

                    break;

                }

            }else{ // process as HTML string

                $this->_ProcessMultiCellText($cellData, $cellWidth, $rowHeight, $cellIndex);

            }

            $cellIndex++;

        }

        $this->FillColor = $curFillColor;
        $this->_FillCell = $curFillStyle;

        $this->_PageAdded = false;

    }

    /**
     * Performs all calculations and property assignments
     * before calling TCPDF::MultiCell method to write to PDF
     *
     * Text content is parsed as HTML to TCPDF::MultiCell,
     * so all TCPDF valid HTML tags can be used in the text
     *
     * @param string $cellText text to display in cell... as HTML
     * @param float $cellWidth cell width
     * @param float $cellHeight cell height
     * @param int $cellIndex array index of the cell in table row
     * @return null
     * @author Bretton Eveleigh
     * @access private
     * @since 0.1 (2009-12-01)
     */

    private function _ProcessMultiCellText($cellText,$cellWidth,$cellHeight,$cellIndex){

        $textAlign = "L"; // left by default

        if(isset($this->_CellAlignment[$cellIndex]) && strlen($this->_CellAlignment[$cellIndex]) == 1){

            $textAlign = $this->_CellAlignment[$cellIndex];

        }

        $this->MultiCell($cellWidth, $cellHeight,$cellText, 1, $textAlign, $this->_FillCell, 0, '', '', true, 0, true, true, $cellHeight);

        if($this->_HCellSpace) $this->x += (float) $this->_HCellSpace;

    }

    /**
     * Performs all calculations and property assignments
     * before calling TCPDF::MultiCell method to write
     * image bounds to PDF, and thereafter process and
     * write an image to PDF
     *
     * 26 Jan 2010 - added support for different image formats, was
     * restricted to JPEG previously
     *
     * @param object $pdfImage the pdfImage object to write to PDF
     * @param float $cellWidth cell width
     * @param float $cellHeight cell height
     * @return null
     * @author Bretton Eveleigh
     * @access private
     * @since 0.1 (2009-12-01)
     * @revised 26 Jan 2010
     */

    private function _ProcessMultiCellImage($pdfImage, $cellWidth, $cellHeight){

        // process the fill:
        $curFillStyle = $this->_FillCell;

        if(!$this->_FillImageCell) $this->_FillCell = 0; // no fill

        // first place the table cell...
        $cellStartX = $this->x; // store x

        $this->MultiCell($cellWidth, $cellHeight, "" , 1, "L", $this->_FillCell, 0);

        $cellEndX = $this->x; // store cell x
        
        // check that the image fits into the cell limits... else resize it using pdfimage scaling methods
        if($pdfImage->GetImageWidth() >= $cellWidth){

            $pdfImage->ScaleWidthTo($cellWidth - ($this->GetLineWidth() * 4));

        }
        
        if($pdfImage->GetImageHeight() >= $cellHeight){

            $pdfImage->ScaleHeightTo($cellHeight - ($this->GetLineWidth() * 4));

        }

        // process any H/V alignment:
        $hAlignShim = 0;
        $vAlignShim = 0;

        $hAlign = $pdfImage->GetHorizontalAlignment(); // default is left
        $vAlign = $pdfImage->GetVerticalAlignment(); // default is top

        switch($hAlign){

            case 'right':
                $hAlignShim = $cellWidth - ($pdfImage->GetImageWidth() + ($this->LineWidth * 2));
            break;

            case 'center':
                $hAlignShim = ($cellWidth - $pdfImage->GetImageWidth()) / 2;
            break;

        }

        switch($vAlign){

            case 'bottom':
                $vAlignShim = $cellHeight - ($pdfImage->GetImageHeight() + ($this->GetLineWidth() * 2));
            break;

            case 'middle':
                $vAlignShim = ($cellHeight - $pdfImage->GetImageHeight()) / 2;
            break;

        }

        if($pdfImage->Exists()){

			$this->x = $cellStartX; // move back to start x

            if($vAlignShim==0) $vAlignShim = $this->GetLineWidth() * 2;
            if($hAlignShim==0) $hAlignShim = $this->GetLineWidth();

            $imageX = (float) $this->x+$hAlignShim;
            $imageY = (float) $this->y+$vAlignShim;

            // place the image...
            $this->Image($pdfImage->GetImagePath(), $imageX, $imageY, $pdfImage->GetImageWidth(), $pdfImage->GetImageHeight(), $pdfImage->GetImageFileType());

        }

        $this->_FillCell = $curFillStyle; // restore the cell fill style

        $this->x = $cellEndX;

        if($this->_HCellSpace) $this->x += (float) $this->_HCellSpace;

    }
    
    // SETTERS:

    /**
     * Set the minimum cell height, if cell height is less,
     * it is resized to the height, if set it overrides any
     * fixed height set previously
     *
     * @param float $height sets prop [priv $this->_CellMinimumHeight]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.3 (2010-01-01)
     */

    public function SetCellMinimumHeight($height){

        $this->_CellFixedHeight = null;

        $this->_CellMinimumHeight = (float) $height;

    }

    /**
     * Set cell height as fixed height, if set it overrides any
     * minimum height set previously
     *
     * @param float $height sets prop [priv $this->_CellFixedHeight]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.3 (2010-01-01)
     */


    public function SetCellFixedHeight($height){

        $this->_CellMinimumHeight = null;

        $this->_CellFixedHeight = (float) $height;

    }

    /**
     * Set table header cell height as fixed height
     *
     * @param float $height sets prop [priv $this->_HeaderFixedHeight]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.4.1 (2010-02-18)
     */


    public function SetHeaderCellFixedHeight($height){

        $this->_HeaderCellsFixedHeight = (float) $height;

    }



    /**
     * Set whether the table header is repeated auto per page
     *
     * @param bool $repeat set prop [$this->_AutoRepeatTableHeader]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.1 (2009-12-01)
     */

    public function SetTableHeaderPerPage($var){

        $this->_AutoRepeatTableHeader = (bool) $var;

    }

    public function SetTableHeaderFirstTablePerPageOnly($var){

        $this->_HeaderFirstTablePerPageOnly = (bool) $var;

    }

    /**
     * Sets the table header/cell text horizontal aligment via array,
     * example: array('C','L',null,'R',''R):
     * 'C' = center
     * 'R' = right
     * 'L' = left
     *
     * @param array $ArrayCellAlignment set prop [priv $this->_CellAlignment]
     * @return null
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetCellAlignment($ArrayCellAlignment){

        $this->_CellAlignment = $ArrayCellAlignment;

    }

    /**
     * Sets the table column widths via array
     * example: array('20','30','auto',100)
     *
     * Please note that the functionality of the 'auto'
     * assignment is experimental and needs more work
     * to make it totally accurate
     *
     * @param array $ArrayCellWidths set prop [priv $this->_CellWidths]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    /**
     *  @todo sort out the 'auto' width assignment, to make it accurate
     */

    public function SetCellWidths($ArrayCellWidths){

        $this->_CellWidths = $ArrayCellWidths;

    }

    /**
     * Sets the cell fill per table row:
     *
     * 0: no fill
     * 1: fill all rows
     * 2: fill alternate rows
     *
     * @param int $int set prop [priv $this->_CellFillStyle]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetCellFillStyle($int){

        $this->_CellFillStyle = (int) $int;

    }

    /**
     * Set whether an 'image cell' will have the cell's
     * fill style applied:
     *
     * @param bool $fill set prop [priv $this->_FillImageCell]
     * @return null
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetFillImageCell($fill){

        $this->_FillImageCell = (bool) $fill;

    }

    /**
     * Set the horizontal spacing between table cells
     *
     * @param float $var set prop [priv $this->_HCellSpace]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetHCellSpace($var){

        $this->_HCellSpace = (float) $var;

    }

    /**
     * Set the vertical spacing between table cells
     *
     * @param float $var set prop [priv $this->_VCellSpace]
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetVCellSpace($var){

        $this->_VCellSpace = (float) $var;

    }

    /**
     * Set the table header cells background color,
     * args are stored as array [priv $this->_HeaderCellsFillColor]
     *
     * @param int $R set the red RGB value
     * @param int $G set the green RGB value
     * @param int $B set the blue RGB value
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetHeaderCellsFillColor($R,$G,$B){

        $this->_HeaderCellsFillColor = array('R'=>$R,'G'=>$G,'B'=>$B);

    }

    /**
     * Set custom table row fill colors, per row,
     * by table row index, example:
     *
     * $tablearray = array(
     *    array('Hello'),   #--> row index 1
     *    array('World)   #--> row index 2
     * );
     *
     * $colorarray = array(
     *    array(150,150,150), #--> color of row indexed as 1
     *    array(255,255,255)  #--> color of row indexed as 2
     * );
     *
     * @param array $colorsArray multi dim array of RGB values for each row
     * @author Bretton Eveleigh
     * @access private
     * @since 0.2 (2009-12-20)
     */

    public function SetTableRowFillColors(Array $colorsArray){

        $this->_TableRowFillColors = $colorsArray;

    }

    /**
     * Set the table header cells font/text color,
     * args are stored as array [priv $this->_HeaderCellsFontColor ]
     *
     * @param int $R set the red RGB value
     * @param int $G set the green RGB value
     * @param int $B set the blue RGB value
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetHeaderCellsFontColor($R,$G,$B){

        $this->_HeaderCellsFontColor = array('R'=>$R,'G'=>$G,'B'=>$B);

    }

    /**
     * Note that this method is depreciated, since TCPDF::MultiCell
     * is set to process cell string data as HTML, so the text
     * formatting can now be achieved by HTML tags like <B>text</B>,
     * <I>text</I>... the HTML must be compatible with TCPDF's HTML
     * requirements.
     *
     * @author Bretton Eveleigh
     * @access public
     * @since 0.2 (2009-12-20)
     */

    public function SetHeaderCellsFontStyle($var){

        $this->_HeaderCellsFontStyle = $var;

    }

    /**
     * Set the table cells font/text color,
     * args are stored as array [priv $this->_CellFontColor]
     *
     * @param int $R set the red RGB value
     * @param int $G set the green RGB value
     * @param int $B set the blue RGB value
     * @author Bretton Eveleigh
     * @access public
     * @since 0.3 (2010-01-10)
     */

    public function SetCellFontColor($R,$G,$B){

        $this->_CellFontColor = array('R'=>$R,'G'=>$G,'B'=>$B);

    }

    /**
     * The height/area above the footer that the
     * table must not enter, if needed, added the
     * bottom margin...
     *
     * @param float $float
     * @author Bretton Eveleigh
     * @access public
     * @since 0.3 (2010-01-10)
     */

    public function SetFooterExclusionZone($float){

        $this->_FooterExclusionZone = (float) $float;

    }

    /**
     * Set horizontal x coord for the left top corner
     * of the table
     *
     * @param float $x the pdf x coord for table top left
     * @author Bretton Eveleigh
     * @access public
     * @since 0.3 (2010-01-10)
     */

    public function SetTableX($x){

        $this->_TableX = (float) $x;

    }

    /**
     * Set horizontal y coord for the left top corner
     * of the table
     *
     * @param float $y the pdf y coord for table top left
     * @author Bretton Eveleigh
     * @access public
     * @since 0.3 (2010-01-10)
     */

    public function SetTableY($y){

        $this->_TableY = (float) $y;

    }
    
    // PRIVATE GETTERS:

    /**
     * Calc/return the rows max cell height
     * and cell width
     *
     * @param array $tableRow array of row cell data
     * @param int $rowIndex the array index of the current table row
     * @return the calculated max row height height
     * @author Bretton Eveleigh
     * @access public
     * @since 0.4 (2010-02-18)
     * @updated (2010-02-23) - bug fix
     */

    private function _GetRowHeight($tableRow, $rowIndex=0){ // new version...

        $maxRowHeight = 0;

       // if header cell and fixed height is defined:
       if($this->_IsTableHeader && (float) $this->_HeaderCellsFixedHeight > 0){

            $maxRowHeight = (float) $this->_HeaderCellsFixedHeight;

       // if data cell and data cell fixed height is defined:
       }else if(!$this->_IsTableHeader && (float) $this->_CellFixedHeight > 0){

            $maxRowHeight = (float) $this->_CellFixedHeight;

        // for all other cells we calc the height with routine below
        }else{

            foreach($tableRow as $cellIndex=>$cellData){ // loop through cells in row

                // check that the cell is not an object, if so process object...

                $cellWidth = $this->_GetCellWidth($cellIndex); // get the cell width

                if(is_object($cellData) && !is_string($cellData)){ //process the string... into table cell...

                    $className = strtolower(get_class($cellData));

                    switch($className){

                        case 'pdfimage':    //  a PDF image object

                            $cellHeight = $cellData->GetImageHeight();

                            $cellHeight += ($this->LineWidth * 4);

                        break;

                        case 'simplexmlelement': // a SimpleXMLElement node

                            $cellData = trim($cellData);

                            $cellHeight = $this->_GetCellHeight($cellData,$cellWidth);

                        break;

                    }

                }else{  // a text string, could be HTML...
                    $cellData = trim($cellData);
                    $cellHeight = $this->_GetCellHeight($cellData,$cellWidth);
                }

                if($cellHeight > $maxRowHeight) $maxRowHeight = (float) $cellHeight;

                $cellIndex++;

            }

        }

        /**
         * if a minimum cell height is defined, check
         * that the row height is not smaller, if it is
         * then set it to the defined minumum
         * cell height
         */

        if(
           !$this->_IsTableHeader &&
           !is_null($this->_CellMinimumHeight) &&
           (float) $this->_CellMinimumHeight > $maxRowHeight){

            $maxRowHeight = (float) $this->_CellMinimumHeight;

        }

        return($maxRowHeight);

    }

    /**
     * Calc/return the cells height based on cell text length
     * and cell width
     *
     * @param string $cellText the table cell text
     * @param float $cellWidth the cell width
     * @return the calculated cell height
     * @author Bretton Eveleigh
     * @access public
     * @since 0.4 (2010-01-10)
     * @revised 2010-02-17 - replaced methods -
     *      ::_GetLineHeight, ::_GetRowHeight and :: _GetMultiCellNumLines
     *      they have been deleted
     */

    private function _GetCellHeight($cellText,$cellWidth){

        //echo "start page: ".$this->page;

        $this->startTransaction();

        $cellTopY = 0;

        $this->SetY($cellTopY);

        $this->MultiCell($cellWidth, 2, (string) $cellText, 1, "L", 0, 2,$this->x, $this->y,true,0,true);

        $cellBottomY = $this->y;
        
        //$endPage = $this->page;

        $this->rollbackTransaction($this);

        $cellHeight = $cellBottomY - $cellTopY;
        //echo ".... end page: ".$endPage."...cell height".$cellHeight."<BR/>";

        return($cellHeight);

    }

    /**
     * Get the cells width as defined in cell/column
     * width array [priv $this->_CellWidths]
     *
     * @param int $cellIndex the array index of the cell
     * @return cell width
     * @author Bretton Eveleigh
     * @access public
     * @since 0.1 (2009-12-01)
     */

    private function _GetCellWidth($cellIndex){

        /**
         * @todo revised the 'auto' cell width calc to make it 100% accurate, it's not 100% at the moment(v 0.4)
         */

        $pageMargins = $this->GetMargins();

        $cellWidth = 25; // default

        if($this->_CellWidths && isset($this->_CellWidths[$cellIndex])){

            // custom column widths have been defined
            if($this->_CellWidths[$cellIndex]=='auto'){ // calculate width of auto columns

                // loop through the custom cell widths

                $autoWidthCells = array();
                $allocatedWidth = 0;

                foreach($this->_CellWidths as $key=>$cW){

                    if($cW=='auto'){

                        $autoWidthCells[] = $key;

                    }else if( (float) $cW > 0){

                        $allocatedWidth += (float) $cW;

                    }

                }

                $unallocatedWidth = $this->getPageWidth() - $pageMargins['left'] - $pageMargins['right'] - $allocatedWidth;

                // add any cell spacing:

                if($this->_HCellSpace) $unallocatedWidth -= ($this->_HCellSpace * (sizeof($this->_TableHeaders) -1));

                $cellAvailableWidth = $unallocatedWidth / sizeof($autoWidthCells);

                foreach($autoWidthCells as $key) $this->_CellWidths[$key] = $cellAvailableWidth;

                $cellWidth = $cellAvailableWidth;

            }else if($this->_CellWidths[$cellIndex] > 0) {

                $cellWidth = (float) $this->_CellWidths[$cellIndex];

            }

        }else{

            if($this->_TableHeaders){

                // we will attempt to automatically calculate the cells widths, cells will be equally spaced across page width...

                $columnCnt = sizeof($this->_TableHeaders);

            }else{

                $columnCnt = sizeof($this->_TableData[0]);

            }

            $cellWidth = ($this->GetPageWidth() - ($pageMargins['left']+$pageMargins['right'])) / $columnCnt;

        }

        return($cellWidth);

    }

    //Page header
    public function Header() {
    }

    // Page footer
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', '', 9);
        // Page number
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }

}