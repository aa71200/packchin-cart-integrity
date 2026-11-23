<?php
declare(strict_types=1);

namespace Packchin\Kits\Core;

/** Evaluates only explicit, immutable inputs; no WordPress or commerce calls. */
final class Evaluator {
	private int $operations      = 0;
	private array $units         = array();
	private const MAX_OPERATIONS = 20000;

	public function evaluate( array $definition, array $configuration, array $catalog ): array {
		if ( array_diff( array_keys( $configuration ), array( 'selections', 'bundleQuantity', 'answers', 'budgetMinor', 'definitionHash' ) ) ) {
			throw new \InvalidArgumentException( 'Unknown configuration field.' ); }
		$this->operations = 0;
		$this->units      = array_column( $definition['units'] ?? array(), null, 'id' );
		foreach ( $catalog as &$product ) {
			foreach ( $definition['attributes'] ?? array() as $attribute ) {
				if ( ! empty( $attribute['unit'] ) && isset( $product['attributes'][ $attribute['id'] ] ) ) {
					$product['attributes'][ $attribute['id'] ] = $this->normalize( $product['attributes'][ $attribute['id'] ], $attribute['unit'] ); }
			}
			foreach ( $definition['resources'] ?? array() as $resource ) {
				foreach ( array( 'provide', 'consume' ) as $direction ) {
					if ( ! empty( $resource['unit'] ) && isset( $product['resources'][ $direction ][ $resource['id'] ] ) ) {
						$product['resources'][ $direction ][ $resource['id'] ] = $this->normalize( $product['resources'][ $direction ][ $resource['id'] ], $resource['unit'] ); }
				}
			}
		} unset( $product );
		$selections     = $configuration['selections'] ?? array();
		$bundleQuantity = $configuration['bundleQuantity'] ?? 1;
		if ( ! is_array( $selections ) || count( $selections ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid selections.' ); }
		$componentIds = array_column( $definition['components'] ?? array(), 'id' );
		foreach ( $selections as $componentId => $entries ) {
			if ( ! in_array( $componentId, $componentIds, true ) || ! is_array( $entries ) || ! array_is_list( $entries ) || count( $entries ) > 5000 ) {
				throw new \InvalidArgumentException( 'Invalid component selections.' ); }
			$seen = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					throw new \InvalidArgumentException( 'Invalid selection.' ); }
				$this->assertSelection( $entry );
				if ( isset( $seen[ $entry['productId'] ] ) ) {
					throw new \InvalidArgumentException( 'Duplicate product selection.' ); }
				$seen[ $entry['productId'] ] = true;
			}
		}
		if ( ! is_int( $bundleQuantity ) || $bundleQuantity < 1 || $bundleQuantity > 10000 ) {
			throw new \InvalidArgumentException( 'Invalid bundle quantity.' );
		}
		$results = array();
		$answers = $configuration['answers'] ?? array();
		if ( ! is_array( $answers ) || count( $answers ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid answers.' ); }
		$questions = array_column( $definition['questions'] ?? array(), null, 'id' );
		foreach ( $answers as $questionId => $answerValue ) {
			if ( ! is_string( $answerValue ) || ! isset( $questions[ $questionId ] ) || ! in_array( $answerValue, array_column( $questions[ $questionId ]['answers'], 'value' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid answer.' ); }
		}
		foreach ( $questions as $question ) {
			if ( ! isset( $answers[ $question['id'] ] ) && ! empty( $question['required'] ) ) {
				$results[] = array(
					'ruleId'     => 'question:' . $question['id'],
					'severity'   => 'hard',
					'status'     => 'incompatible',
					'code'       => 'ANSWER_REQUIRED',
					'messageKey' => 'hamsaz.answer_required',
					'components' => array(),
				);
			}
			foreach ( $question['answers'] as $answer ) {
				if ( ( $answers[ $question['id'] ] ?? null ) === $answer['value'] && isset( $answer['requirement'] ) ) {
					$definition['rules'][] = array(
						'id'         => 'answer:' . $question['id'],
						'severity'   => 'hard',
						'assert'     => $answer['requirement'],
						'code'       => 'ANSWER_REQUIREMENT_FAILED',
						'message'    => $answer['message'] ?? null,
						'messageKey' => 'hamsaz.answer_requirement_failed',
					);
				}
			}
		}
		if ( isset( $configuration['budgetMinor'] ) ) {
			if ( ! is_int( $configuration['budgetMinor'] ) || $configuration['budgetMinor'] < 0 || $configuration['budgetMinor'] > 9007199254740991 ) {
				throw new \InvalidArgumentException( 'Invalid budget.' ); }
			$definition['rules'][] = array(
				'id'         => 'budget',
				'severity'   => 'hard',
				'assert'     => array(
					'type'  => 'compare',
					'op'    => 'lte',
					'left'  => array( 'type' => 'price' ),
					'right' => array(
						'type'  => 'literal',
						'value' => $configuration['budgetMinor'],
					),
				),
				'code'       => 'BUDGET_EXCEEDED',
				'messageKey' => 'hamsaz.budget_exceeded',
			);
		}
		$disabled        = array();
		$activationInput = $selections;
		foreach ( $definition['components'] ?? array() as $component ) {
			if ( ! isset( $component['enabledWhen'] ) ) {
				continue; }
			$enabled = $this->node( $component['enabledWhen'], $activationInput, $catalog, $bundleQuantity );
			if ( $enabled instanceof Unknown ) {
				$results[] = array(
					'ruleId'     => 'activation:' . $component['id'],
					'severity'   => 'hard',
					'status'     => 'unknown',
					'code'       => 'COMPONENT_STATE_UNKNOWN',
					'components' => array( $component['id'] ),
				);
			} elseif ( $enabled === false ) {
				$disabled[] = $component['id'];
				if ( ! empty( $selections[ $component['id'] ] ) ) {
					$results[] = array(
						'ruleId'     => 'activation:' . $component['id'],
						'severity'   => 'hard',
						'status'     => 'incompatible',
						'code'       => 'DISABLED_COMPONENT_SELECTION',
						'components' => array( $component['id'] ),
					); }
				$selections[ $component['id'] ] = array();
			}
		}
		foreach ( $definition['components'] ?? array() as $component ) {
			if ( in_array( $component['id'], $disabled, true ) ) {
				continue; }
			if ( ( $component['selectionMode'] ?? '' ) === 'single' && count( $selections[ $component['id'] ] ?? array() ) > 1 ) {
				$results[] = array(
					'ruleId'     => 'selection_mode:' . $component['id'],
					'severity'   => 'hard',
					'status'     => 'incompatible',
					'code'       => 'SINGLE_SELECTION_REQUIRED',
					'components' => array( $component['id'] ),
				); }
			$quantity = 0;
			foreach ( $selections[ $component['id'] ] ?? array() as $entry ) {
				$this->assertSelection( $entry );
				$quantity = $this->add( $quantity, $entry['quantity'] );
			}
			if ( $quantity < $component['min'] || $quantity > $component['max'] ) {
				$results[] = array(
					'ruleId'     => 'cardinality:' . $component['id'],
					'severity'   => 'hard',
					'status'     => 'incompatible',
					'code'       => 'CARDINALITY_OUT_OF_RANGE',
					'messageKey' => 'hamsaz.cardinality_out_of_range',
					'expected'   => array(
						'min' => $component['min'],
						'max' => $component['max'],
					),
					'actual'     => $quantity,
					'components' => array( $component['id'] ),
				);
			}
		}
		foreach ( $definition['attributes'] ?? array() as $attribute ) {
			if ( empty( $attribute['required'] ) ) {
				continue; }
			foreach ( $selections as $componentId => $entries ) {
				if ( ! empty( $attribute['componentIds'] ) && ! in_array( $componentId, $attribute['componentIds'], true ) ) {
					continue; }
				foreach ( $entries as $entry ) {
					$value = $catalog[ $entry['productId'] ]['attributes'][ $attribute['id'] ] ?? null;
					if ( $value === null || $value instanceof Unknown ) {
						$results[] = array(
							'ruleId'     => 'required:' . $componentId . ':' . $entry['productId'] . ':' . $attribute['id'],
							'severity'   => 'hard',
							'status'     => 'unknown',
							'code'       => 'REQUIRED_ATTRIBUTE_MISSING',
							'attribute'  => $attribute['id'],
							'productId'  => $entry['productId'],
							'components' => array( $componentId ),
						);
					}
				}
			}
		}
		foreach ( $definition['rules'] ?? array() as $rule ) {
			$condition = isset( $rule['when'] ) ? $this->node( $rule['when'], $selections, $catalog, $bundleQuantity ) : true;
			if ( $condition === false ) {
				continue;
			}
			$value     = $condition instanceof Unknown ? $condition : $this->node( $rule['assert'], $selections, $catalog, $bundleQuantity );
			$status    = $value instanceof Unknown ? 'unknown' : ( $value === true ? 'compatible' : 'incompatible' );
			$results[] = array(
				'ruleId'     => $rule['id'],
				'severity'   => $rule['severity'],
				'status'     => $status,
				'code'       => $value instanceof Unknown ? $value->reason : ( $status === 'incompatible' ? ( $rule['code'] ?? 'RULE_FAILED' ) : 'RULE_PASSED' ),
				'messageKey' => $rule['messageKey'] ?? 'hamsaz.rule_result',
				'message'    => $rule['message'] ?? null,
				'expected'   => $rule['assert']['right']['value'] ?? null,
				'actual'     => isset( $rule['assert']['left'] ) ? $this->visible( $this->node( $rule['assert']['left'], $selections, $catalog, $bundleQuantity ) ) : null,
				'components' => $this->affectedComponents( $rule ),
			);
		}
		$status = 'compatible';
		foreach ( $results as $result ) {
			if ( $result['severity'] === 'hard' && $result['status'] === 'incompatible' ) {
				$status = 'incompatible';
				break;
			}
			if ( $result['severity'] === 'hard' && $result['status'] === 'unknown' ) {
				$status = 'unknown';
			} elseif ( $status === 'compatible' && $result['severity'] === 'warning' && $result['status'] !== 'compatible' ) {
				$status = 'warning';
			}
		}
		$price = $this->node( array( 'type' => 'price' ), $selections, $catalog, $bundleQuantity );
		if ( $price instanceof Unknown ) {
			$results[] = array(
				'ruleId'     => 'price',
				'severity'   => 'hard',
				'status'     => 'unknown',
				'code'       => $price->reason,
				'components' => array(),
			);
			if ( $status !== 'incompatible' ) {
				$status = 'unknown'; }
		}
		$score = 0;
		foreach ( $definition['rules'] ?? array() as $rule ) {
			if ( $rule['severity'] !== 'preference' ) {
				continue;
			} foreach ( $results as $row ) {
				if ( $row['ruleId'] === $rule['id'] && $row['status'] === 'compatible' ) {
					$score += $rule['weight'] ?? 1; }
			}
		}
		return array(
			'status'             => $status,
			'canPurchase'        => ( $status === 'compatible' || $status === 'warning' ) && ! ( $price instanceof Unknown ),
			'results'            => $results,
			'subtotalMinor'      => $price instanceof Unknown ? null : $price,
			'priceStatus'        => $price instanceof Unknown ? 'unknown' : 'known',
			'disabledComponents' => $disabled,
			'preferenceScore'    => $score,
		);
	}

	private function node( array $node, array $selections, array $catalog, int $bundleQuantity ): mixed {
		if ( ++$this->operations > self::MAX_OPERATIONS ) {
			return new Unknown( 'EVALUATION_LIMIT' );
		}
		$type = $node['type'] ?? '';
		try {
			return match ( $type ) {
				'literal' => isset( $node['unit'] ) ? $this->normalize( $node['value'] ?? null, $node['unit'] ) : ( $node['value'] ?? null ),
				'selected' => $this->selected( $node, $selections ),
				'allowed' => $this->allowed( $node, $selections ),
				'count' => $this->count( $node, $selections ),
				'attribute' => $this->attribute( $node, $selections, $catalog ),
				'sum' => $this->sum( $node, $selections, $catalog ),
				'resource' => $this->resource( $node, $selections, $catalog ),
				'price' => $this->price( $selections, $catalog, $bundleQuantity ),
				'compare' => $this->compare( $node, $selections, $catalog, $bundleQuantity ),
				'calculate' => $this->calculate( $node, $selections, $catalog, $bundleQuantity ),
				'all' => $this->logical( $node, $selections, $catalog, $bundleQuantity, true ),
				'any' => $this->logical( $node, $selections, $catalog, $bundleQuantity, false ),
				'not' => $this->negate( $this->node( $node['node'], $selections, $catalog, $bundleQuantity ) ),
				'in' => $this->contains( $node, $selections, $catalog, $bundleQuantity ),
				default => new Unknown( 'UNKNOWN_NODE' ),
			};
		} catch ( \OverflowException $error ) {
			return new Unknown( 'NUMERIC_OVERFLOW' ); }
	}

	private function normalize( mixed $value, string $unit ): int|Unknown {
		if ( ! is_int( $value ) || ! isset( $this->units[ $unit ] ) ) {
			return new Unknown( 'UNIT_DATA_INVALID' ); }
		try {
			return Units::normalize( $value, $this->units[ $unit ] );
		} catch ( \Throwable $error ) {
			return new Unknown( 'UNIT_PRECISION_OR_OVERFLOW' ); }
	}

	private function calculate( array $node, array $selections, array $catalog, int $bundleQuantity ): int|Unknown {
		$left  = $this->node( $node['left'], $selections, $catalog, $bundleQuantity );
		$right = $this->node( $node['right'], $selections, $catalog, $bundleQuantity );
		if ( ! is_int( $left ) || ! is_int( $right ) ) {
			return new Unknown( 'CALCULATION_DATA_MISSING' ); }
		try {
			return match ( $node['op'] ) {
				'add' => $this->add( $left, $right ), 'subtract' => $right === PHP_INT_MIN ? new Unknown( 'NUMERIC_OVERFLOW' ) : $this->add( $left, -$right ), 'multiply' => $this->multiply( $left, $right ),
				'divide' => $right === 0 || ( $left === PHP_INT_MIN && $right === -1 ) || $left % $right !== 0 ? new Unknown( 'CALCULATION_PRECISION_OR_DIVISION' ) : intdiv( $left, $right ), default => new Unknown( 'UNKNOWN_OPERATOR' ),
			};
		} catch ( \OverflowException $error ) {
			return new Unknown( 'NUMERIC_OVERFLOW' ); }
	}

	private function selected( array $node, array $selections ): bool {
		foreach ( $selections[ $node['component'] ] ?? array() as $entry ) {
			if ( in_array( $entry['productId'], $node['productIds'] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	private function allowed( array $node, array $selections ): bool {
		$entries = $selections[ $node['component'] ] ?? array();
		if ( ! $entries ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry['productId'], $node['productIds'], true ) ) {
				return false;
			}
		}
		return true;
	}

	private function count( array $node, array $selections ): int {
		$total = 0;
		foreach ( $selections[ $node['component'] ] ?? array() as $entry ) {
			$total = $this->add( $total, (int) $entry['quantity'] );
		}
		return $total;
	}

	private function attribute( array $node, array $selections, array $catalog ): mixed {
		$entries = $selections[ $node['component'] ] ?? array();
		if ( count( $entries ) !== 1 ) {
			return new Unknown( 'ATTRIBUTE_REQUIRES_ONE_SELECTION' );
		}
		$id = $entries[0]['productId'];
		return $catalog[ $id ]['attributes'][ $node['attribute'] ] ?? new Unknown( 'ATTRIBUTE_MISSING' );
	}

	private function sum( array $node, array $selections, array $catalog ): int|float|Unknown {
		$total   = 0;
		$decimal = false;
		foreach ( $selections[ $node['component'] ] ?? array() as $entry ) {
			if ( is_float( $catalog[ $entry['productId'] ]['attributes'][ $node['attribute'] ] ?? null ) ) {
				$decimal = true; }
		}
		foreach ( $selections[ $node['component'] ] ?? array() as $entry ) {
			$value = $catalog[ $entry['productId'] ]['attributes'][ $node['attribute'] ] ?? null;
			if ( $decimal && ( is_int( $value ) || is_float( $value ) ) ) {
				$value = $this->decimal( $value ); }
			if ( ! is_int( $value ) ) {
				return new Unknown( 'ATTRIBUTE_MISSING' );
			}
			$total = $this->add( $total, $this->multiply( $value, $entry['quantity'] ) );
		}
		return $decimal ? $total / 1000000 : $total;
	}

	private function resource( array $node, array $selections, array $catalog ): int|Unknown {
		$total  = 0;
		$groups = isset( $node['component'] ) ? array( $node['component'] => $selections[ $node['component'] ] ?? array() ) : $selections;
		foreach ( $groups as $entries ) {
			foreach ( $entries as $entry ) {
				$values = $catalog[ $entry['productId'] ]['resources'][ $node['direction'] ] ?? null;
				if ( ! is_array( $values ) ) {
					return new Unknown( 'RESOURCE_DATA_MISSING' );
				}
				$value = $values[ $node['resource'] ] ?? 0;
				if ( ! is_int( $value ) ) {
					return new Unknown( 'RESOURCE_DATA_INVALID' );
				}
				$total = $this->add( $total, $this->multiply( $value, $entry['quantity'] ) );
			}
		}
		return $total;
	}

	private function price( array $selections, array $catalog, int $bundleQuantity ): int|Unknown {
		$total = 0;
		foreach ( $selections as $entries ) {
			foreach ( $entries as $entry ) {
				$price = $catalog[ $entry['productId'] ]['priceMinor'] ?? null;
				if ( ! is_int( $price ) || $price < 0 ) {
					return new Unknown( 'PRICE_MISSING' );
				}
				$total = $this->add( $total, $this->multiply( $price, $this->multiply( $entry['quantity'], $bundleQuantity ) ) );
			}
		}
		return $total;
	}

	private function compare( array $node, array $selections, array $catalog, int $bundleQuantity ): bool|Unknown {
		$left  = $this->node( $node['left'], $selections, $catalog, $bundleQuantity );
		$right = $this->node( $node['right'], $selections, $catalog, $bundleQuantity );
		if ( $left instanceof Unknown || $right instanceof Unknown ) {
			return new Unknown( 'COMPARISON_DATA_MISSING' );
		}
		if ( ( is_int( $left ) || is_float( $left ) ) && ( is_int( $right ) || is_float( $right ) ) && ( is_float( $left ) || is_float( $right ) ) ) {
			$left  = $this->decimal( $left );
			$right = $this->decimal( $right );
			if ( $left instanceof Unknown || $right instanceof Unknown ) {
				return new Unknown( 'NUMBER_PRECISION_OR_OVERFLOW' ); }
		}
		if ( get_debug_type( $left ) !== get_debug_type( $right ) ) {
			return new Unknown( 'TYPE_MISMATCH' );
		}
		return match ( $node['op'] ) {
			'eq' => $left === $right,
			'neq' => $left !== $right,
			'lt' => $left < $right,
			'lte' => $left <= $right,
			'gt' => $left > $right,
			'gte' => $left >= $right,
			default => new Unknown( 'UNKNOWN_OPERATOR' ),
		};
	}

	private function decimal( int|float $value ): int|Unknown {
		$scaled = $value * 1000000;
		if ( ! is_finite( (float) $scaled ) || abs( $scaled ) > 9007199254740991 || abs( $scaled - round( $scaled ) ) > 0.000001 ) {
			return new Unknown( 'NUMBER_PRECISION_OR_OVERFLOW' ); }
		return (int) round( $scaled );
	}

	private function logical( array $node, array $selections, array $catalog, int $bundleQuantity, bool $all ): bool|Unknown {
		$unknown = false;
		foreach ( $node['nodes'] ?? array() as $child ) {
			$value = $this->node( $child, $selections, $catalog, $bundleQuantity );
			if ( $value instanceof Unknown ) {
				$unknown = true;
			} elseif ( $value === ! $all ) {
				return ! $all;
			}
		}
		return $unknown ? new Unknown( 'CONDITION_DATA_MISSING' ) : $all;
	}

	private function negate( mixed $value ): bool|Unknown {
		return $value instanceof Unknown ? $value : ( is_bool( $value ) ? ! $value : new Unknown( 'LOGICAL_TYPE_MISMATCH' ) );
	}

	private function contains( array $node, array $selections, array $catalog, int $bundleQuantity ): bool|Unknown {
		$value = $this->node( $node['value'], $selections, $catalog, $bundleQuantity );
		if ( $value instanceof Unknown ) {
			return $value; }
		$unknown = false;
		foreach ( $node['values'] ?? array() as $candidate ) {
			$left  = $value;
			$right = $candidate;
			if ( ( is_int( $left ) || is_float( $left ) ) && ( is_int( $right ) || is_float( $right ) ) && ( is_float( $left ) || is_float( $right ) ) ) {
				$left  = $this->decimal( $left );
				$right = $this->decimal( $right );
			}
			if ( $left instanceof Unknown || $right instanceof Unknown || get_debug_type( $left ) !== get_debug_type( $right ) ) {
				$unknown = true;
				continue; }
			if ( $left === $right ) {
				return true; }
		}
		return $unknown ? new Unknown( 'MEMBERSHIP_DATA_INVALID' ) : false;
	}

	private function assertSelection( array $entry ): void {
		if ( array_diff( array_keys( $entry ), array( 'productId', 'quantity' ) ) ) {
			throw new \InvalidArgumentException( 'Unknown selection field.' ); }
		if ( ! isset( $entry['productId'], $entry['quantity'] ) || ! is_int( $entry['productId'] ) || ! is_int( $entry['quantity'] ) || $entry['productId'] < 1 || $entry['quantity'] < 1 || $entry['quantity'] > 10000 ) {
			throw new \InvalidArgumentException( 'Invalid selection.' );
		}
	}

	private function add( int $a, int $b ): int {
		$result = $a + $b;
		if ( ! is_int( $result ) || abs( $result ) > 9007199254740991 ) {
			throw new \OverflowException( 'Numeric overflow.' );
		}
		return $result;
	}

	private function multiply( int $a, int $b ): int {
		$result = $a * $b;
		if ( ! is_int( $result ) || abs( $result ) > 9007199254740991 ) {
			throw new \OverflowException( 'Numeric overflow.' );
		}
		return $result;
	}

	private function visible( mixed $value ): mixed {
		return $value instanceof Unknown ? null : $value;
	}

	private function affectedComponents( array $rule ): array {
		$ids  = array();
		$walk = static function ( mixed $value ) use ( &$walk, &$ids ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['component'] ) && is_string( $value['component'] ) ) {
				$ids[ $value['component'] ] = true;
			}
			foreach ( $value as $child ) {
				$walk( $child );
			}
		};
		$walk( $rule );
		return array_keys( $ids );
	}
}
