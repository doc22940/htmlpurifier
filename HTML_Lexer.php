<?php

/*
Forgivingly lexes SGML style documents, aka HTML, XML, XHMTML, you name it.

TODO:
 * Reread the XML spec and make sure I got everything right
 * Add support for CDATA sections
 * Have comments output with the leading and trailing --s
 * Optimize and benchmark
 * Check MF_Text behavior: shouldn't the info in there be raw (entities parsed?)

*/

class HTML_Lexer
{
    
    // does this version of PHP support utf8 as entity function charset?
    var $_entity_utf8;
    
    function HTML_Lexer() {
        $this->_entity_utf8 = version_compare(PHP_VERSION, '5', '>=');
    }
    
    // this is QUITE a knotty problem
    // 
    // The main trouble is that, even while assuming UTF-8 is what we're
    // using, we've got to deal with HTML entities (like &mdash;)
    // Not even sure if the PHP 5 decoding function does that. Plus,
    // SimpleTest doesn't use UTF-8!
    // 
    // However, we MUST parse everything possible, because once you get
    // to the HTML generator, it will escape everything possible (although
    // that may not be correct, and we should be using htmlspecialchars() ).
    // 
    // Nevertheless, strictly XML speaking, we cannot assume any character
    // entities are defined except the htmlspecialchars() ones, so leaving
    // the entities inside HERE is not acceptable. (plus, htmlspecialchars
    // might convert them anyway). So EVERYTHING must get parsed.
    // 
    // We may need to roll our own character entity lookup table. It's only
    // about 250, fortunantely, the decimal/hex ones map cleanly to UTF-8.
    function parseData($string) {
        // we may want to let the user do a different char encoding,
        // although there is NO REASON why they shouldn't be able
        // to convert it to UTF-8 before they pass it to us
        
        // no support for less than PHP 4.3
        if ($this->_entity_utf8) {
            // PHP 5+, UTF-8 is nicely supported
            return @html_entity_decode($string, ENT_QUOTES, 'UTF-8');
        } else {
            // PHP 4, do compat stuff
            $string = html_entity_decode($string, ENT_QUOTES, 'ISO-8859-1');
            // get the numeric UTF-8 stuff
            $string = preg_replace('/&#(\d+);/me', "chr(\\1)", $string);
            $string = preg_replace('/&#x([a-f0-9]+);/mei',"chr(0x\\1)",$string);
            // get the stringy UTF-8 stuff
            return $string;
        }
    }
    
    function nextQuote($string, $offset = 0) {
        $quotes = array('"', "'");
        return $this->next($string, $quotes, $offset);
    }
    
    function nextWhiteSpace($string, $offset = 0) {
        $spaces = array(chr(0x20), chr(0x9), chr(0xD), chr(0xA));
        return $this->next($string, $spaces, $offset);
    }
    
    function next($haystack, $needles, $offset = 0) {
        if (is_string($needles)) {
            $string_needles = $needles;
            $needles = array();
            $size = strlen($string_needles);
            for ($i = 0; $i < $size; $i++) {
                $needles[] = $string_needles{$i};
            }
        }
        $positions = array();
        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle, $offset);
            if ($position !== false) {
                $positions[] = $position;
            }
        }
        return empty($positions) ? false : min($positions);
    }
    
    function tokenizeHTML($string) {
        
        // some quick checking (if empty, return empty)
        $string = (string) $string;
        if ($string == '') return array();
        
        $cursor = 0; // our location in the text
        $inside_tag = false; // whether or not we're parsing the inside of a tag
        $array = array(); // result array
        
        // infinite loop protection
        // has to be pretty big, since html docs can be big
        // we're allow two hundred thousand tags... more than enough?
        $loops = 0;
        
        while(true) {
            
            // infinite loop protection
            if (++$loops > 200000) return array();
            
            $position_next_lt = strpos($string, '<', $cursor);
            $position_next_gt = strpos($string, '>', $cursor);
            
            // triggers on "<b>asdf</b>" but not "asdf <b></b>"
            if ($position_next_lt === $cursor) {
                $inside_tag = true;
                $cursor++;
            }
            
            if (!$inside_tag && $position_next_lt !== false) {
                // We are not inside tag and there still is another tag to parse
                $array[] = new
                    HTMLPurifier_Token_Text(
                        html_entity_decode(
                            substr(
                                $string, $cursor, $position_next_lt - $cursor
                            ),
                            ENT_QUOTES
                        )
                    );
                $cursor  = $position_next_lt + 1;
                $inside_tag = true;
                continue;
            } elseif (!$inside_tag) {
                // We are not inside tag but there are no more tags
                // If we're already at the end, break
                if ($cursor === strlen($string)) break;
                // Create Text of rest of string
                $array[] = new
                    HTMLPurifier_Token_Text(
                        html_entity_decode(
                            substr(
                                $string, $cursor
                            ),
                            ENT_QUOTES
                        )
                    );
                break;
            } elseif ($inside_tag && $position_next_gt !== false) {
                // We are in tag and it is well formed
                // Grab the internals of the tag
                $segment = substr($string, $cursor, $position_next_gt-$cursor);
                
                // Check if it's a comment
                if (
                    substr($segment,0,3) == '!--' &&
                    substr($segment,strlen($segment)-2,2) == '--'
                ) {
                    $array[] = new
                        HTMLPurifier_Token_Comment(
                            substr(
                                $segment, 3, strlen($segment) - 5
                            )
                        );
                    $inside_tag = false;
                    $cursor = $position_next_gt + 1;
                    continue;
                }
                
                // Check if it's an end tag
                $is_end_tag = (strpos($segment,'/') === 0);
                if ($is_end_tag) {
                    $type = substr($segment, 1);
                    $array[] = new HTMLPurifier_Token_End($type);
                    $inside_tag = false;
                    $cursor = $position_next_gt + 1;
                    continue;
                }
                
                // Check if it is explicitly self closing, if so, remove
                // trailing slash. Remember, we could have a tag like <br>, so
                // any later token processing scripts must convert improperly
                // classified EmptyTags from StartTags.
                $is_self_closing= (strpos($segment,'/') === strlen($segment)-1);
                if ($is_self_closing) {
                    $segment = substr($segment, 0, strlen($segment) - 1);
                }
                
                // Check if there are any attributes
                $position_first_space = $this->nextWhiteSpace($segment);
                if ($position_first_space === false) {
                    if ($is_self_closing) {
                        $array[] = new HTMLPurifier_Token_Empty($segment);
                    } else {
                        $array[] = new HTMLPurifier_Token_Start($segment);
                    }
                    $inside_tag = false;
                    $cursor = $position_next_gt + 1;
                    continue;
                }
                
                // Grab out all the data
                $type = substr($segment, 0, $position_first_space);
                $attribute_string =
                    trim(
                        substr(
                            $segment, $position_first_space
                        )
                    );
                $attributes = $this->tokenizeAttributeString($attribute_string);
                if ($is_self_closing) {
                    $array[] = new HTMLPurifier_Token_Empty($type, $attributes);
                } else {
                    $array[] = new HTMLPurifier_Token_Start($type, $attributes);
                }
                $cursor = $position_next_gt + 1;
                $inside_tag = false;
                continue;
            } else {
                $array[] = new
                    HTMLPurifier_Token_Text(
                        '<' .
                        html_entity_decode(
                            substr($string, $cursor),
                            ENT_QUOTES
                        )
                    );
                break;
            }
            break;
        }
        return $array;
    }
    
    function tokenizeAttributeString($string) {
        $string = (string) $string;
        if ($string == '') return array();
        $array = array();
        $cursor = 0;
        $in_value = false;
        $i = 0;
        $size = strlen($string);
        
        // if we have unquoted attributes, the parser expects a terminating
        // space, so let's guarantee that there's always a terminating space.
        $string .= ' ';
        
        // infinite loop protection
        $loops = 0;
        
        while(true) {
            
            // infinite loop protection
            // if we've looped 1000 times, abort. Nothing good can come of this 
            if (++$loops > 1000) return array();
            
            if ($cursor >= $size) {
                break;
            }
            $position_next_space = $this->nextWhiteSpace($string, $cursor);
            //scroll to the last whitespace before text
            while ($position_next_space === $cursor) {
                $cursor++;
                $position_next_space = $this->nextWhiteSpace($string, $cursor);
            }
            $position_next_equal = strpos($string, '=', $cursor);
            if ($position_next_equal !== false &&
                 ($position_next_equal < $position_next_space ||
                  $position_next_space === false)) {
                //attr="asdf"
                // grab the key
                $key = trim(
                    substr(
                        $string, $cursor, $position_next_equal - $cursor
                    )
                );
                
                // set cursor right after the equal sign
                $cursor = $position_next_equal + 1;
                
                // consume all spaces after the equal sign
                $position_next_space = $this->nextWhiteSpace($string, $cursor);
                while ($position_next_space === $cursor) {
                    $cursor++;
                    $position_next_space=$this->nextWhiteSpace($string,$cursor);
                }
                
                // if we've hit the end, assign the key an empty value and abort
                if ($cursor >= $size) {
                    $array[$key] = '';
                    break;
                }
                
                // find the next quote
                $position_next_quote = $this->nextQuote($string, $cursor);
                
                // if the quote is not where the cursor is, we're dealing
                // with an unquoted attribute
                if ($position_next_quote !== $cursor) {
                    if ($key) {
                        $array[$key] = trim(substr($string, $cursor,
                          $position_next_space - $cursor));
                    }
                    $cursor = $position_next_space + 1;
                    continue;
                }
                
                // otherwise, regular attribute
                $quote = $string{$position_next_quote};
                $position_end_quote = strpos(
                    $string, $quote, $position_next_quote + 1
                );
                
                // check if the ending quote is missing
                if ($position_end_quote === false) {
                    // it is, assign it to the end of the string
                    $position_end_quote = $size;
                }
                
                $value = substr($string, $position_next_quote + 1,
                  $position_end_quote - $position_next_quote - 1);
                if ($key) {
                    $array[$key] = html_entity_decode($value, ENT_QUOTES);
                }
                $cursor = $position_end_quote + 1;
            } else {
                //boolattr
                if ($position_next_space === false) {
                    $position_next_space = $size;
                }
                $key = substr($string, $cursor, $position_next_space - $cursor);
                if ($key) {
                    $array[$key] = $key;
                }
                $cursor = $position_next_space + 1;
            }
        }
        return $array;
    }
    
}

// uses the PEAR class XML_HTMLSax3 to parse XML
//   only shares the tokenizeHTML() function
class HTML_Lexer_Sax extends HTML_Lexer
{
    
    var $tokens = array();
    
    function tokenizeHTML($html) {
        $this->tokens = array();
        $parser=& new XML_HTMLSax3();
        $parser->set_object($this);
        $parser->set_element_handler('openHandler','closeHandler');
        $parser->set_data_handler('dataHandler');
        $parser->set_escape_handler('escapeHandler');
        $parser->set_option('XML_OPTION_ENTITIES_PARSED', 1);
        $parser->parse($html);
        return $this->tokens;
    }
    
    function openHandler(&$parser, $name, $attrs, $closed) {
        if ($closed) {
            $this->tokens[] = new HTMLPurifier_Token_Empty($name, $attrs);
        } else {
            $this->tokens[] = new HTMLPurifier_Token_Start($name, $attrs);
        }
        return true;
    }
    
    function closeHandler(&$parser, $name) {
        // HTMLSax3 seems to always send empty tags an extra close tag
        // check and ignore if you see it:
        // [TESTME] to make sure it doesn't overreach
        if ($this->tokens[count($this->tokens)-1]->type == 'empty') {
            return true;
        }
        $this->tokens[] = new HTMLPurifier_Token_End($name);
        return true;
    }
    
    function dataHandler(&$parser, $data) {
        $this->tokens[] = new HTMLPurifier_Token_Text($data);
        return true;
    }
    
    function escapeHandler(&$parser, $data) {
        if (strpos($data, '-') === 0) {
            $this->tokens[] = new HTMLPurifier_Token_Comment($data);
        }
        return true;
    }
    
}

?>